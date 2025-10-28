<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class ReportService
{
    protected $dateFrom;
    protected $dateTo;
    protected $filters;
    protected $cacheKey;

    /**
     * Generate report data
     */
    abstract public function generate(): array;

    /**
     * Set date range
     */
    public function setDateRange($from, $to): self
    {
        $this->dateFrom = Carbon::parse($from)->startOfDay();
        $this->dateTo = Carbon::parse($to)->endOfDay();
        
        return $this;
    }

    /**
     * Set date range from preset
     */
    public function setDatePreset(string $preset): self
    {
        $now = Carbon::now();
        
        switch ($preset) {
            case 'today':
                $this->dateFrom = $now->copy()->startOfDay();
                $this->dateTo = $now->copy()->endOfDay();
                break;
                
            case 'yesterday':
                $this->dateFrom = $now->copy()->subDay()->startOfDay();
                $this->dateTo = $now->copy()->subDay()->endOfDay();
                break;
                
            case 'this_week':
                $this->dateFrom = $now->copy()->startOfWeek();
                $this->dateTo = $now->copy()->endOfWeek();
                break;
                
            case 'last_week':
                $this->dateFrom = $now->copy()->subWeek()->startOfWeek();
                $this->dateTo = $now->copy()->subWeek()->endOfWeek();
                break;
                
            case 'this_month':
                $this->dateFrom = $now->copy()->startOfMonth();
                $this->dateTo = $now->copy()->endOfMonth();
                break;
            default:
                throw new \InvalidArgumentException("Invalid date preset: {$preset}");
        }
        
        return $this;
    }

    /**
     * Set filters
     */
    public function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * Get data with caching
     */
    protected function getCachedData(string $key, \Closure $callback)
    {
        if (!config('reports.cache.enabled')) {
            return $callback();
        }

        $cacheKey = $this->generateCacheKey($key);
        $ttl = config('reports.cache.ttl', 3600);

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    /**
     * Generate cache key
     */
    protected function generateCacheKey(string $key): string
    {
        return sprintf(
            'report:%s:%s:%s:%s',
            $key,
            $this->dateFrom->format('Y-m-d'),
            $this->dateTo->format('Y-m-d'),
            md5(json_encode($this->filters ?? []))
        );
    }

    /**
     * Get previous period dates for comparison
     */
    protected function getPreviousPeriodDates(): array
    {
        $diff = $this->dateFrom->diffInDays($this->dateTo);
        
        $previousFrom = $this->dateFrom->copy()->subDays($diff + 1)->startOfDay();
        $previousTo = $this->dateFrom->copy()->subDay()->endOfDay();
        
        return [
            'from' => $previousFrom,
            'to' => $previousTo,
        ];
    }

    /**
     * Calculate percentage change
     */
    protected function calculatePercentageChange($current, $previous): array
    {
        if ($previous == 0) {
            return [
                'amount' => $current,
                'percentage' => $current > 0 ? 100 : 0,
                'direction' => $current > 0 ? 'up' : 'neutral',
            ];
        }

        $change = $current - $previous;
        $percentage = ($change / $previous) * 100;

        return [
            'amount' => $change,
            'percentage' => round($percentage, 2),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral'),
        ];
    }

    /**
     * Format currency
     */
    protected function formatCurrency($amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    /**
     * Format percentage
     */
    protected function formatPercentage($value): string
    {
        return number_format($value, 1) . '%';
    }
}