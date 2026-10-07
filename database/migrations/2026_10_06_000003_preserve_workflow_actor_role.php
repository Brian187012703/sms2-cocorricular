<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflow_history', 'actor_role')) {
            Schema::table('workflow_history', fn (Blueprint $table) => $table->string('actor_role', 50)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('workflow_history', fn (Blueprint $table) => $table->dropColumn('actor_role'));
    }
};
