<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backup-database')]
#[Description('Back up the current database')]
class BackupDatabase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DatabaseBackupService $backupService): void
    {
        $this->info('Starting database backup...');

        $logger = function ($message) {
            $this->info($message);
        };

        $backupPath = $backupService->backupDatabase($logger);

        if ($backupPath) {
            $this->info('Database backed up successfully.');
        } else {
            $this->error('Failed to back up the database.');
        }
    }
}
