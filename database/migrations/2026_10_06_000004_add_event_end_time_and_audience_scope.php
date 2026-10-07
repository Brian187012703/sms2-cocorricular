<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'end_time')) {
                $table->dateTime('end_time')->nullable()->after('event_date');
            }
            if (!Schema::hasColumn('events', 'audience_type')) {
                $table->enum('audience_type', ['Inclusive', 'Exclusive'])->default('Inclusive')->after('event_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'end_time')) {
                $table->dropColumn('end_time');
            }
            if (Schema::hasColumn('events', 'audience_type')) {
                $table->dropColumn('audience_type');
            }
        });
    }
};
