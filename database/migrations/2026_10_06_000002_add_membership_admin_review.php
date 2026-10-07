<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('club_memberships', 'admin_review')) {
            Schema::table('club_memberships', fn (Blueprint $table) => $table->string('admin_review', 50)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('club_memberships', fn (Blueprint $table) => $table->dropColumn('admin_review'));
    }
};
