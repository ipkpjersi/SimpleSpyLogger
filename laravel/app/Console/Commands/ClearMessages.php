<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:clear-messages {--source= : Only clear one source (e.g. discord)} {--force : Skip confirmation}')]
#[Description('Delete messages (and their revisions via cascade). Defaults to ALL sources unless --source is given.')]
class ClearMessages extends Command
{
    public function handle(): int
    {
        $source = $this->option('source');
        $query = Message::query();
        if ($source) {
            $query->where('source', $source);
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No messages to clear.');
            return self::SUCCESS;
        }

        $scope = $source ? "source='{$source}'" : 'ALL sources';
        if (!$this->option('force')) {
            if (!$this->confirm("This will delete {$count} message row(s) ({$scope}). Continue?", false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} message row(s).");
        return self::SUCCESS;
    }
}
