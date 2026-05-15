<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // RSCPlus external_ids switched from sha1 to sha256, so any previously
        // ingested rscplus rows no longer match what the scraper will produce
        // on re-run. Drop them so a re-scrape repopulates with the new format.
        // Other sources (discord, lemmy, twitter) use upstream-native IDs that
        // are unaffected by this change.
        DB::table('messages')->where('source', 'rscplus')->delete();

        Schema::table('messages', function (Blueprint $table) {
            $table->string('external_id', 128)->change();
            $table->string('referenced_external_id', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        // sha256-derived rscplus external_ids exceed 64 chars, so drop them
        // before shrinking the column or the alter will fail.
        DB::table('messages')->where('source', 'rscplus')->delete();

        Schema::table('messages', function (Blueprint $table) {
            $table->string('external_id', 64)->change();
            $table->string('referenced_external_id', 64)->nullable()->change();
        });
    }
};
