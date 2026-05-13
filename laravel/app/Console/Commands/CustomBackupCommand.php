<?php

namespace App\Console\Commands;

use Spatie\Backup\Commands\BackupCommand;

class CustomBackupCommand extends BackupCommand
{
    // Here we maintain the same signature but modify the default for --disable-notifications
    protected $signature = 'backup:run {--filename=} {--only-db} {--db-name=*} {--only-files} {--only-to-disk=} {--disable-notifications=true} {--timeout=} {--tries=}';

    protected $description = 'Run the spatie laravel-backup with notifications disabled by default. This is the recommended backup command.';

    public function handle(): int
    {
        $this->info('Running custom backup command...');
        // Interpret the value of the --disable-notifications option as a string.
        // This is necessary because we set a default for a boolean option.
        // In Laravel, boolean options typically check for presence, not value.
        if ($this->option('disable-notifications') !== 'false') {
            $this->input->setOption('disable-notifications', true);
        } else {
            $this->input->setOption('disable-notifications', false);
        }

        // Call the parent handle method since we don't want to modify any underlying logic.
        return parent::handle();
    }
}
