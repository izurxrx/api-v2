<?php

namespace App\Rules;

use App\Services\FacilityAvailabilityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FacilityAvailable implements ValidationRule
{
    protected $facilityId;
    protected $startDateTime;
    protected $endDateTime;
    protected $requestedQuantity;
    protected $excludeBookingId;
    protected $excludeGuestEntryId;

    public function __construct(
        $facilityId, 
        $startDateTime, 
        $endDateTime, 
        $requestedQuantity = 1,
        $excludeBookingId = null, 
        $excludeGuestEntryId = null
    ) {
        $this->facilityId = $facilityId;
        $this->startDateTime = $startDateTime;
        $this->endDateTime = $endDateTime;
        $this->requestedQuantity = $requestedQuantity;
        $this->excludeBookingId = $excludeBookingId;
        $this->excludeGuestEntryId = $excludeGuestEntryId;
    }
    
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            // ✅ Validate datetime format
            $start = Carbon::parse($this->startDateTime);
            $end = Carbon::parse($this->endDateTime);
            
            // ✅ Validate end is after start
            if ($end->lte($start)) {
                $fail('End time must be after start time.');
                return;
            }
            
            // ✅ Validate not too far in the future (e.g., max 2 years)
            if ($start->gt(now()->addYears(2))) {
                $fail('Cannot book more than 2 years in advance.');
                return;
            }
            
            $service = app(FacilityAvailabilityService::class);
            
            // ✅ Check available quantity
            $available = $service->getAvailableQuantity(
                $this->facilityId,
                $start,
                $end,
                $this->excludeBookingId,
                $this->excludeGuestEntryId
            );

            // ✅ Compare requested vs available
            if ($this->requestedQuantity > $available) {
                if ($available === 0) {
                    $fail('This facility is fully booked for the selected time period.');
                } else {
                    $fail("Only {$available} unit(s) available. You requested {$this->requestedQuantity}.");
                }
            }
            
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            Log::error('Invalid datetime format in FacilityAvailable rule', [
                'start' => $this->startDateTime,
                'end' => $this->endDateTime,
                'error' => $e->getMessage()
            ]);
            $fail('Invalid date/time format provided.');
            
        } catch (\Exception $e) {
            Log::error('Error checking facility availability', [
                'facility_id' => $this->facilityId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $fail('Unable to check facility availability. Please try again.');
        }
    }
}