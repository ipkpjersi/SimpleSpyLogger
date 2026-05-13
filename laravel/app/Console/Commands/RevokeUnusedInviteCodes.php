<?php

namespace App\Console\Commands;

use App\Models\InviteCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:revoke-unused-invite-codes')]
#[Description('Revokes any unused invite codes.')]
class RevokeUnusedInviteCodes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {

        $deletedCount = InviteCode::where('used', false)->delete();

        $this->info("Successfully deleted {$deletedCount} unused invite codes.");

        return $deletedCount;
    }
}
