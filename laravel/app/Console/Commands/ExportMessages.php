<?php

namespace App\Console\Commands;

use App\Services\MessageExportService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:export-messages {path : Output file (one source) or directory (all sources)} {--source= : Single source to export; omit to export each source to its own file under {path}} {--since= : Only export messages captured at/after this date (YYYY-MM-DD or ISO 8601)}')]
#[Description('Export messages from the database to JSON, in the same shape as the ingest API.')]
class ExportMessages extends Command
{
    public function handle(MessageExportService $exporter): int
    {
        $path = $this->argument('path');
        $source = $this->option('source');
        $sinceRaw = $this->option('since');
        $since = null;
        if ($sinceRaw) {
            try {
                $since = Carbon::parse($sinceRaw);
            } catch (\Throwable $e) {
                $this->error("Could not parse --since='{$sinceRaw}'");
                return self::FAILURE;
            }
        }

        try {
            $count = $exporter->exportToFile($path, $source, $since);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Exported {$count} message row(s) to {$path}.");
        return self::SUCCESS;
    }
}
