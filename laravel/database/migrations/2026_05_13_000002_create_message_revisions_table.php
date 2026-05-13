<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('message_id')
                ->constrained('messages')
                ->cascadeOnDelete();

            $table->longText('content')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('source_edited_at')->nullable();
            $table->timestamp('captured_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['message_id', 'source_edited_at'], 'revisions_message_edited_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_revisions');
    }
};
