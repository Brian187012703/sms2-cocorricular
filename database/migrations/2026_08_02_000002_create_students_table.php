<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('students')) {
            Schema::create('students', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('student_id', 30)->unique();
                $table->string('first_name', 50);
                $table->string('last_name', 50);
                $table->string('email', 100)->unique();
                $table->string('course', 50);
                $table->integer('year_level');
                $table->enum('status', ['Active', 'Graduated', 'Suspended'])->default('Active');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
