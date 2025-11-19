<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_extensions', function (Blueprint $table) {
            // Add facility release tracking
            $table->boolean('is_released')->default(false)->after('is_overtime');
            $table->datetime('released_at')->nullable()->after('is_released');
            $table->foreignId('released_by')->nullable()->after('released_at')->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_extensions', function (Blueprint $table) {
            $table->dropForeign(['released_by']);
            $table->dropColumn(['is_released', 'released_at', 'released_by']);
        });
    }
};
