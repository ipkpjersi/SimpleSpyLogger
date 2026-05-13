<?php

namespace Database\Seeders;

use App\Models\Message;
use App\Models\MessageRevision;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DiscordSampleSeeder extends Seeder
{
    public function run(): void
    {
        Message::where('source', 'discord')->delete();

        $now = Carbon::now();

        $users = [
            ['id' => '111000000000000001', 'username' => 'ken',        'display' => 'Ken',     'bot' => false],
            ['id' => '111000000000000002', 'username' => 'alice42',     'display' => 'Alice',   'bot' => false],
            ['id' => '111000000000000003', 'username' => 'bob.smith',   'display' => 'Bob',     'bot' => false],
            ['id' => '111000000000000004', 'username' => 'aat_bot',     'display' => 'AAT Bot', 'bot' => true],
        ];
        $guild = ['id' => '222000000000000001', 'name' => 'Anime Lovers'];
        $channels = [
            ['id' => '333000000000000001', 'name' => 'general'],
            ['id' => '333000000000000002', 'name' => 'recommendations'],
        ];
        $dmChannel = '444000000000000001';

        // [author_index, channel_index|null=dm, content, minutes_ago, flags]
        $scenarios = [
            [0, 0, "hey everyone, watching Frieren right now, it's amazing", 240, []],
            [1, 0, "ohh i need to start that one, is it on crunchyroll?", 238, []],
            [0, 0, "yeah! and the OP is one of the best i've heard this year", 237, []],
            [2, 0, "i'm more of a JJK guy myself but ill give it a shot", 235, []],
            [3, 0, "[reminder] weekly watch party tonight at 8pm UTC", 180, []],
            [1, 1, "any recs for short series? 12 ep or less", 150, []],
            [0, 1, "violet evergarden, oshi no ko s1, sonny boy", 148, []],
            [2, 1, "+1 on oshi no ko, devastating in the best way", 147, []],
            [1, 1, "okay grabbing oshi no ko then thanks", 146, []],
            [0, 0, "anyone playing osrs?", 90, []],
            [2, 0, "depends, ironman or main?", 89, []],
            [0, 0, "main, grinding zulrah", 88, []],
            [1, 0, "i hate zulrah lmao good luck", 87, []],
            [0, 0, "thx", 86, []],
            [2, 0, "what'd you do this weekend", 60, []],
            [0, 0, "mostly worked on this logging project", 59, []],
            [1, 0, "the discord one? show me when its ready", 58, []],
            [0, 0, "will do", 57, []],
            [1, null, "hey can we chat privately for a sec", 30, []],
            [0, null, "sure whats up", 29, []],
            [1, null, "im thinking of moving teams, want your honest take", 28, []],
            [0, null, "yeah lets jump on a call", 27, []],
            [2, 0, "oof i posted in the wrong channel", 15, ['deleted' => true]],
            [0, 0, "actually nvm i figured out the bug", 8, ['edited' => true, 'original' => 'help i think im going crazy with this bug']],
            [1, 0, "ggez", 2, []],
        ];

        $seq = 0;
        foreach ($scenarios as [$ai, $ci, $content, $minutes, $flags]) {
            $seq++;
            $author = $users[$ai];
            $isDm = $ci === null;
            $sentAt = $now->copy()->subMinutes($minutes);

            $attrs = [
                'source' => 'discord',
                'external_id' => (string) (9_000_000_000_000_000_000 + $seq),
                'container_external_id' => $isDm ? null : $guild['id'],
                'container_name' => $isDm ? null : $guild['name'],
                'channel_external_id' => $isDm ? $dmChannel : $channels[$ci]['id'],
                'channel_name' => $isDm ? null : $channels[$ci]['name'],
                'visibility' => $isDm ? 'private' : 'public',
                'author_external_id' => $author['id'],
                'author_username' => $author['username'],
                'author_display_name' => $author['display'],
                'author_bot' => $author['bot'],
                'content' => $content,
                'sent_at' => $sentAt,
                'captured_at' => $sentAt,
                'payload' => [
                    'type' => 0,
                    'channel_type' => $isDm ? 1 : 0,
                    'attachments' => [],
                    'embeds' => [],
                ],
            ];

            if ($flags['deleted'] ?? false) {
                $attrs['deleted_at'] = $sentAt->copy()->addMinutes(2);
            }
            if ($flags['edited'] ?? false) {
                $attrs['source_edited_at'] = $sentAt->copy()->addMinutes(1);
            }

            $msg = Message::create($attrs);

            if ($flags['edited'] ?? false) {
                MessageRevision::create([
                    'message_id' => $msg->id,
                    'content' => $flags['original'],
                    'payload' => $attrs['payload'],
                    'source_edited_at' => null,
                    'captured_at' => $sentAt,
                    'created_at' => $sentAt->copy()->addSeconds(30),
                ]);
            }
        }
    }
}
