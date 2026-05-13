<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Short identifier for the upstream service: 'discord', 'twitter', 'rscplus', etc.
            $table->string('source', 32);

            // Upstream-native ID (Discord snowflake, tweet ID, RSC sequence, etc.).
            $table->string('external_id', 64);

            // Outer container the message lives in. Discord guild, RSC world, etc.
            $table->string('container_external_id', 64)->nullable();
            $table->string('container_name', 255)->nullable();

            // The conversation/room within the container. Discord channel,
            // RSC chat-type (public/private/clan/trade/dueling), Twitter conversation, etc.
            $table->string('channel_external_id', 64)->nullable();
            $table->string('channel_name', 255)->nullable();

            // 'public' | 'private' | 'group' (kept as string for portability across services).
            $table->string('visibility', 16)->default('public');

            $table->string('author_external_id', 64);
            $table->string('author_username', 255);
            $table->string('author_display_name', 255)->nullable();
            $table->boolean('author_bot')->default(false);

            $table->longText('content')->nullable();

            // ID of the message this one replies to / quotes / references.
            $table->string('referenced_external_id', 64)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('source_edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('captured_at')->nullable();

            // Full source-specific blob (attachments, embeds, reactions, retweet info, RSC packet, ...).
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique(['source', 'external_id'], 'messages_source_external_unique');
            $table->index(['source', 'channel_external_id', 'sent_at'], 'messages_source_channel_idx');
            $table->index(['source', 'author_external_id', 'sent_at'], 'messages_source_author_idx');
            $table->index(['source', 'container_external_id', 'sent_at'], 'messages_source_container_idx');
            $table->index('referenced_external_id', 'messages_referenced_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
