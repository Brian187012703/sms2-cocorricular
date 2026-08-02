<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->enum('category', ['Academic', 'Cultural', 'Sports', 'Advocacy', 'Religious'])->default('Academic');
            $table->text('description')->nullable();
            $table->string('adviser_name', 150)->default('Unassigned');
            $table->enum('status', ['Active', 'Pending Charter', 'Suspended'])->default('Active');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
