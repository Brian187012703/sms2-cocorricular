<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('election_votes', function (Blueprint $table) {
            if (! Schema::hasColumn('election_votes', 'candidate_id')) {
                $table->unsignedInteger('candidate_id')->nullable()->index();
            }
            if (! Schema::hasColumn('election_votes', 'position')) {
                $table->string('position', 100)->nullable();
            }
            if (! Schema::hasColumn('election_votes', 'vote_hash')) {
                $table->string('vote_hash', 64)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('election_votes', function (Blueprint $table) {
            $table->dropColumn(['candidate_id', 'position', 'vote_hash']);
        });
    }
};
