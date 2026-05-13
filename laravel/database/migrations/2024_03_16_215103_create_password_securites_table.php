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
        Schema::create('password_securities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            // Make sure to get rid of the related securities info when a User is deleted.
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->boolean('google2fa_enable')->default(false);
            $table->string('google2fa_secret')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_securities');
    }
};
