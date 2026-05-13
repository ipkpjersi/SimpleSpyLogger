<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('staff_action_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // ID of the user who is performing the action
            $table->unsignedBigInteger('target_id'); // ID of the user being acted upon
            $table->string('action'); // e.g., 'ban', 'remove_avatar'
            $table->string('message')->nullable(); // e.g., 'removed avatar 456845967.png for user username (ID: 398)'
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('target_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_action_log');
    }
};
