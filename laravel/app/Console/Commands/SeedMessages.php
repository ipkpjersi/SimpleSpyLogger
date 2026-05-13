<?php

namespace App\Console\Commands;

use Database\Seeders\DiscordSampleSeeder;
use Database\Seeders\RscplusSampleSeeder;
use Database\Seeders\TwitterSampleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:seed-messages {--source= : Only seed one source (discord, twitter, rscplus)}')]
#[Description('Seed the messages table with sample data for one or all sources.')]
class SeedMessages extends Command
{
    public function handle(): int
    {
        $only = $this->option('source');
        $map = [
            'discord' => DiscordSampleSeeder::class,
            'twitter' => TwitterSampleSeeder::class,
            'rscplus' => RscplusSampleSeeder::class,
        ];

        if ($only !== null) {
            if (!isset($map[$only])) {
                $this->error("Unknown source '{$only}'. Use one of: " . implode(', ', array_keys($map)));
                return self::FAILURE;
            }
            $seeders = [$only => $map[$only]];
        } else {
            $seeders = $map;
        }

        foreach ($seeders as $name => $class) {
            $this->info("Seeding {$name}...");
            $this->call('db:seed', ['--class' => $class, '--force' => true]);
        }

        return self::SUCCESS;
    }
}
