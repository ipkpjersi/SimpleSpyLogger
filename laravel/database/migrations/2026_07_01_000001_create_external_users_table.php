<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Id -> username cache for accounts external to this system (people we
        // only have a numeric id for, e.g. Twitter DM partners). Populated by a
        // resolver that pays per lookup, so once a user is cached we never pay to
        // resolve them again. Keyed by (source, external_id) to mirror the
        // messages table's multi-source design.
        Schema::create('external_users', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('source', 32);
            $table->string('external_id', 64);

            $table->string('username', 255)->nullable();
            $table->string('display_name', 255)->nullable();

            // When the resolver last attempted this id (success or not), so we can
            // skip or back off re-resolving deleted/suspended/protected accounts.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['source', 'external_id'], 'external_users_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_users');
    }
};
