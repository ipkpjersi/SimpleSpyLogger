<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\ConsoleOutput;

#[Signature('app:backup:run')]
#[Description('Alias for backup:run command. This is the recommended backup command.')]
class AppBackupRunCommand extends Command
{
    public function handle(): void
    {
        $this->info('Running backup:run command via app:backup:run...');
        Artisan::call('backup:run', [], new ConsoleOutput);
        $this->info('The backup:run command via app:backup:run has completed.');
    }
}
