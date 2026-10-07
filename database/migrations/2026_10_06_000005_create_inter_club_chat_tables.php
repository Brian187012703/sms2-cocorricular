<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_channels')) {
            Schema::create('chat_channels', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->enum('type', ['club_group', 'adviser_ssc', 'direct'])->default('club_group');
                $table->unsignedInteger('club_id')->nullable()->index();
                $table->string('description', 255)->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_members')) {
            Schema::create('chat_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('channel_id')->index();
                $table->unsignedInteger('user_id')->index();
                $table->timestamp('last_read_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['channel_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('channel_id')->index();
                $table->unsignedInteger('sender_id')->index();
                $table->text('message');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_members');
        Schema::dropIfExists('chat_channels');
    }
};
