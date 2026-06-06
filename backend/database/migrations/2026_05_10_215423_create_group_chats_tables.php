<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_chats', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('group_chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('group_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_messages');
        Schema::dropIfExists('group_chat_members');
        Schema::dropIfExists('group_chats');
    }
};