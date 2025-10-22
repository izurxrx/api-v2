<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // ====================================================================
        // STEP 1: DATA MIGRATION - Preserve existing data
        // ====================================================================
        
        // Handle existing 'Direct' mode entries at guest_entries level
        // Convert them to 'None' if no details have Direct mode
        DB::statement("
            UPDATE guest_entries ge
            SET discount_mode = 'None',
                discount_amount = 0
            WHERE discount_mode = 'Direct'
            AND NOT EXISTS (
                SELECT 1 FROM guest_entry_details ged 
                WHERE ged.guest_entry_id = ge.id 
                AND ged.discount_mode = 'Direct'
            )
        ");

        // ====================================================================
        // STEP 2: SCHEMA CHANGES - guest_entries table
        // ====================================================================
        
        Schema::table('guest_entries', function (Blueprint $table) {
            // Check if discount_id column exists before adding
            if (!Schema::hasColumn('guest_entries', 'discount_id')) {
                $table->unsignedBigInteger('discount_id')->nullable()->after('discount_mode');
            }
        });

        // Add index if it doesn't exist
        if (!$this->indexExists('guest_entries', 'idx_discount_id')) {
            Schema::table('guest_entries', function (Blueprint $table) {
                $table->index('discount_id', 'idx_discount_id');
            });
        }

        // Add foreign key if it doesn't exist
        if (!$this->foreignKeyExists('guest_entries', 'guest_entries_discount_id_foreign')) {
            Schema::table('guest_entries', function (Blueprint $table) {
                $table->foreign('discount_id', 'guest_entries_discount_id_foreign')
                      ->references('id')
                      ->on('discounts')
                      ->onDelete('set null');
            });
        }

        // Add index for discount_mode if it doesn't exist
        if (!$this->indexExists('guest_entries', 'idx_discount_mode_entries')) {
            Schema::table('guest_entries', function (Blueprint $table) {
                $table->index('discount_mode', 'idx_discount_mode_entries');
            });
        }

        // Update discount_mode enum - Remove 'Direct' from guest_entries
        DB::statement("
            ALTER TABLE guest_entries 
            MODIFY COLUMN discount_mode 
            ENUM('None','Seasonal','Manual') NOT NULL DEFAULT 'None'
            COMMENT 'Entry-level discount mode. Seasonal: auto-applied discount from discounts table. Manual: staff enters discount amount. None: no entry-level discount (may have Direct per-group discounts).'
        ");

        // Update discount_amount comment
        DB::statement("
            ALTER TABLE guest_entries
            MODIFY COLUMN discount_amount DECIMAL(10,2) DEFAULT 0.00
            COMMENT 'Discount amount: calculated from discount_id (Seasonal), staff-entered (Manual), or sum of detail-level Direct discounts'
        ");

        // ====================================================================
        // STEP 3: SCHEMA CHANGES - guest_entry_details table
        // ====================================================================

        // Update discount_mode enum - Remove 'Seasonal' and 'Manual'
        DB::statement("
            ALTER TABLE guest_entry_details
            MODIFY COLUMN discount_mode 
            ENUM('None','Direct') NOT NULL DEFAULT 'None'
            COMMENT 'Detail-level discount mode. None: no discount for this group (may inherit entry-level discount). Direct: specific direct discount from discounts table.'
        ");

        // Add index for discount_mode if it doesn't exist
        if (!$this->indexExists('guest_entry_details', 'idx_discount_mode_details')) {
            Schema::table('guest_entry_details', function (Blueprint $table) {
                $table->index('discount_mode', 'idx_discount_mode_details');
            });
        }

        // Update discount_amount comment
        DB::statement("
            ALTER TABLE guest_entry_details
            MODIFY COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00
            COMMENT 'Calculated discount for this guest group (only when discount_mode=Direct, otherwise 0)'
        ");

        // Update discount_id comment
        DB::statement("
            ALTER TABLE guest_entry_details
            MODIFY COLUMN discount_id BIGINT(20) UNSIGNED DEFAULT NULL 
            COMMENT 'FK to discounts table with category=Direct_Discount (only used when discount_mode=Direct)'
        ");

        // Update final_rate comment for clarity
        DB::statement("
            ALTER TABLE guest_entry_details
            MODIFY COLUMN final_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00
            COMMENT 'base_rate - discount_amount (if Direct mode, otherwise equals base_rate)'
        ");

        // ====================================================================
        // STEP 4: UPDATE VIEWS
        // ====================================================================

        // Drop existing view
        DB::statement('DROP VIEW IF EXISTS guest_entry_summary');

        // Recreate view with new structure
        DB::statement("
            CREATE VIEW guest_entry_summary AS
            SELECT 
                ge.id AS entry_id,
                ge.entry_reference,
                ge.entry_date,
                ge.guest_name,
                ge.discount_mode AS entry_discount_mode,
                ge.discount_id AS entry_discount_id,
                ed.name AS entry_discount_name,
                ed.category AS entry_discount_category,
                ge.discount_amount AS entry_discount_amount,
                ged.id AS detail_id,
                ged.guest_type_name,
                r.rate_name,
                r.rate_category,
                ged.guest_count,
                ged.base_rate,
                ged.discount_mode AS detail_discount_mode,
                ged.discount_id AS detail_discount_id,
                d.name AS detail_discount_name,
                d.category AS detail_discount_category,
                ged.discount_amount AS detail_discount_amount,
                ged.final_rate,
                ged.total_amount
            FROM guest_entries ge
            JOIN guest_entry_details ged ON ge.id = ged.guest_entry_id
            JOIN rates r ON ged.rate_id = r.id
            LEFT JOIN discounts d ON ged.discount_id = d.id
            LEFT JOIN discounts ed ON ge.discount_id = ed.id
            WHERE ge.deleted_at IS NULL 
              AND ged.deleted_at IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // ====================================================================
        // ROLLBACK: Restore original structure
        // ====================================================================

        // Drop the updated view
        DB::statement('DROP VIEW IF EXISTS guest_entry_summary');

        // Restore original guest_entries discount_mode enum
        DB::statement("
            ALTER TABLE guest_entries 
            MODIFY COLUMN discount_mode 
            ENUM('None','Seasonal','Direct','Manual') NOT NULL DEFAULT 'None'
            COMMENT 'Discount mode applied to entire entry. Direct/Seasonal: select from discounts table per group. Manual: enter amount per group.'
        ");

        // Drop foreign key, indexes and column from guest_entries
        Schema::table('guest_entries', function (Blueprint $table) {
            // Drop foreign key if exists
            if ($this->foreignKeyExists('guest_entries', 'guest_entries_discount_id_foreign')) {
                $table->dropForeign('guest_entries_discount_id_foreign');
            }
            
            // Drop indexes if they exist
            if ($this->indexExists('guest_entries', 'idx_discount_id')) {
                $table->dropIndex('idx_discount_id');
            }
            
            if ($this->indexExists('guest_entries', 'idx_discount_mode_entries')) {
                $table->dropIndex('idx_discount_mode_entries');
            }
            
            // Drop column if exists
            if (Schema::hasColumn('guest_entries', 'discount_id')) {
                $table->dropColumn('discount_id');
            }
        });

        // Restore original guest_entry_details discount_mode enum
        DB::statement("
            ALTER TABLE guest_entry_details
            MODIFY COLUMN discount_mode 
            ENUM('None','Seasonal','Direct','Manual') NOT NULL DEFAULT 'None'
            COMMENT 'VALIDATION RULES:
            - None: discount_id=NULL, discount_amount=0, manual_discount_amount=0
            - Seasonal: discount_id=seasonal_discount.id (auto-applied if active), discount_amount=calculated
            - Direct: discount_id=direct_discount.id (user selects), discount_amount=calculated
            - Manual: discount_id=NULL, discount_amount=0, manual_discount_amount=user_input'
        ");

        // Drop index from guest_entry_details if exists
        if ($this->indexExists('guest_entry_details', 'idx_discount_mode_details')) {
            Schema::table('guest_entry_details', function (Blueprint $table) {
                $table->dropIndex('idx_discount_mode_details');
            });
        }

        // Restore original view
        DB::statement("
            CREATE VIEW guest_entry_summary AS
            SELECT 
                ge.id AS entry_id,
                ge.entry_reference,
                ge.entry_date,
                ge.guest_name,
                ged.id AS detail_id,
                ged.guest_type_name,
                r.rate_name,
                r.rate_category,
                ged.guest_count,
                ged.base_rate,
                ged.discount_mode,
                d.name AS discount_name,
                d.category AS discount_category,
                ged.discount_amount,
                ged.final_rate,
                ged.total_amount
            FROM guest_entries ge
            JOIN guest_entry_details ged ON ge.id = ged.guest_entry_id
            JOIN rates r ON ged.rate_id = r.id
            LEFT JOIN discounts d ON ged.discount_id = d.id
            WHERE ge.deleted_at IS NULL 
              AND ged.deleted_at IS NULL
        ");
    }

    /**
     * Check if an index exists on a table
     *
     * @param string $table
     * @param string $index
     * @return bool
     */
    private function indexExists($table, $index)
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        
        $result = DB::select(
            "SELECT COUNT(*) as count 
             FROM information_schema.statistics 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$database, $table, $index]
        );
        
        return $result[0]->count > 0;
    }

    /**
     * Check if a foreign key exists on a table
     *
     * @param string $table
     * @param string $foreignKey
     * @return bool
     */
    private function foreignKeyExists($table, $foreignKey)
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        
        $result = DB::select(
            "SELECT COUNT(*) as count 
             FROM information_schema.table_constraints 
             WHERE constraint_schema = ? 
             AND table_name = ? 
             AND constraint_name = ? 
             AND constraint_type = 'FOREIGN KEY'",
            [$database, $table, $foreignKey]
        );
        
        return $result[0]->count > 0;
    }
};