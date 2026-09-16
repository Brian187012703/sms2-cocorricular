<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username', 60)->unique();
                $table->string('email', 150)->unique();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('password', 255);
                $table->enum('role', ['student', 'club_adviser', 'ssc', 'admin'])->default('student');
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
