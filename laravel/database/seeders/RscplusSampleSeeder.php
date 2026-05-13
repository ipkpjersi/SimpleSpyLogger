<?php

namespace Database\Seeders;

use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class RscplusSampleSeeder extends Seeder
{
    public function run(): void
    {
        Message::where('source', 'rscplus')->delete();

        $now = Carbon::now();

        $worlds = [
            'preservation' => 'Preservation',
            'cabbage' => 'Cabbage',
        ];

        // chat type -> visibility
        $visibilityFor = [
            'public' => 'public',
            'trade' => 'public',
            'global' => 'public',
            'private' => 'private',
            'clan' => 'private',
            'dueling' => 'private',
        ];

        $chatNames = [
            'public' => 'Public chat',
            'private' => 'Private message',
            'clan' => 'Clan chat',
            'trade' => 'Trade chat',
            'global' => 'Global chat',
            'dueling' => 'Dueling chat',
        ];

        // [world, chat_type, author, content, minutes_ago, extra_payload]
        $scenarios = [
            ['preservation', 'public', 'Zezima',              "selling fire runes 50ea, falador west bank",    400, ['x' => 222, 'y' => 451]],
            ['preservation', 'public', 'ThePythonAtePotato',  "anyone need a kbd trip?",                       395, ['x' => 226, 'y' => 442]],
            ['preservation', 'public', 'Vagus',               "wts dragon med helm 80k",                       390, ['x' => 220, 'y' => 449]],
            ['preservation', 'trade',  'Ken',                 "buying yew logs 200ea bulk",                    380, []],
            ['preservation', 'trade',  'Zezima',              "swap law runes for nats 1:1",                   375, []],
            ['preservation', 'global', 'Vagus',               "anyone hosting a clan event tonight?",          360, []],
            ['preservation', 'private','Zezima',              "hey want to team for kbd?",                     300, ['recipient' => 'Ken']],
            ['preservation', 'private','Ken',                 "sure give me 5 min to bank",                    298, ['recipient' => 'Zezima']],
            ['preservation', 'private','Zezima',              "k im at edgeville bank",                        290, ['recipient' => 'Ken']],
            ['preservation', 'private','Ken',                 "coming",                                        289, ['recipient' => 'Zezima']],
            ['preservation', 'clan',   'Ken',                 "anyone want to do a fight pits trip?",          240, ['clan' => 'Pythons']],
            ['preservation', 'clan',   'ThePythonAtePotato',  "im in, gimme 10",                               239, ['clan' => 'Pythons']],
            ['preservation', 'clan',   'Vagus',               "ill watch from the stands lol",                 235, ['clan' => 'Pythons']],
            ['preservation', 'dueling','Ken',                 "wanna stake 100k no armor?",                    180, ['recipient' => 'Vagus']],
            ['preservation', 'dueling','Vagus',               "make it 250k",                                  179, ['recipient' => 'Ken']],
            ['cabbage',      'public', 'Zezima',              "cabbage chops? best world for fast 99 wc",      120, ['x' => 100, 'y' => 200]],
            ['cabbage',      'public', 'Ken',                 "yeah cabbage hands down",                       118, ['x' => 102, 'y' => 199]],
            ['cabbage',      'global', 'ThePythonAtePotato',  "anyone seen the new ardy update?",              100, []],
            ['cabbage',      'public', 'Vagus',               "rofl that lag was insane",                       60, ['x' => 110, 'y' => 205]],
            ['preservation', 'public', 'Ken',                 "gz on the 99",                                   20, ['x' => 220, 'y' => 451]],
            ['preservation', 'public', 'Zezima',              "ty :)",                                          19, ['x' => 220, 'y' => 451]],
        ];

        $seq = 0;
        foreach ($scenarios as [$world, $chatType, $author, $content, $minutes, $extra]) {
            $seq++;
            $sentAt = $now->copy()->subMinutes($minutes);

            Message::create([
                'source' => 'rscplus',
                'external_id' => $world . ':' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
                'container_external_id' => $world,
                'container_name' => $worlds[$world],
                'channel_external_id' => $chatType,
                'channel_name' => $chatNames[$chatType],
                'visibility' => $visibilityFor[$chatType],
                'author_external_id' => $author,
                'author_username' => $author,
                'author_display_name' => null,
                'author_bot' => false,
                'content' => $content,
                'sent_at' => $sentAt,
                'captured_at' => $sentAt,
                'payload' => array_merge(['chat_type' => $chatType, 'world' => $world], $extra),
            ]);
        }
    }
}
