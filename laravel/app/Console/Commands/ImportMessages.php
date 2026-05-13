<?php

namespace App\Console\Commands;

use App\Services\MessageImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:import-messages {file : Path to a JSON file produced by app:export-messages or matching the ingest API shape}')]
#[Description('Import messages from a JSON file into the database.')]
class ImportMessages extends Command
{
    public function handle(MessageImportService $importer): int
    {
        $file = $this->argument('file');
        $this->info("Importing from {$file}...");

        try {
            $stats = $importer->importFromFile($file);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done. created=%d updated=%d deleted=%d skipped=%d',
            $stats['created'], $stats['updated'], $stats['deleted'], $stats['skipped']
        ));
        return self::SUCCESS;
    }
}
