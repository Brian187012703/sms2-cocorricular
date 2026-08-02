<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('event_date');
            $table->string('venue', 150);
            $table->enum('status', ['Upcoming', 'Approved', 'Completed', 'Pending OSA', 'Rejected'])->default('Pending OSA');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
