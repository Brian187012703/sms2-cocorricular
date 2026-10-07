<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('achievements')) {
            Schema::table('achievements', function (Blueprint $table) {
                if (!Schema::hasColumn('achievements', 'approved_by')) {
                    $table->foreignId('approved_by')->nullable()->after('verified_by')->constrained('users')->nullOnDelete();
                }
            });

            // Ensure status column accommodates 50 chars for multi-tier status
            try {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `achievements` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Pending SSC'");
            } catch (\Throwable $e) {
                // Ignore if driver/db doesn't require alteration
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('achievements') && Schema::hasColumn('achievements', 'approved_by')) {
            Schema::table('achievements', function (Blueprint $table) {
                $table->dropForeign(['approved_by']);
                $table->dropColumn('approved_by');
            });
        }
    }
};
