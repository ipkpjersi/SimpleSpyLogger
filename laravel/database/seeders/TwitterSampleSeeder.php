<?php

namespace Database\Seeders;

use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TwitterSampleSeeder extends Seeder
{
    public function run(): void
    {
        Message::where('source', 'twitter')->delete();

        $now = Carbon::now();

        $users = [
            ['id' => '44196397',   'username' => 'elonmusk',      'display' => 'Elon Musk'],
            ['id' => '987001',     'username' => 'ken',          'display' => 'Ken'],
            ['id' => '17919972',   'username' => 'taylorswift13', 'display' => 'Taylor Swift'],
            ['id' => '20536157',   'username' => 'nintendoamerica','display' => 'Nintendo of America'],
        ];

        // [author_index, content, minutes_ago, kind, referenced_external_id|null, visibility, extra_payload]
        $scenarios = [
            [0, "buying twitter was an excellent decision",       720, 'tweet',  null, 'public', ['metrics' => ['likes' => 482103, 'retweets' => 51200]]],
            [1, "ok but did it really though",                    715, 'reply',  '1800000000000000001', 'public', []],
            [3, "Mario Kart World launches next month. Pre-order now.", 600, 'tweet', null, 'public', ['metrics' => ['likes' => 81203, 'retweets' => 12400], 'media' => [['type' => 'image']]]],
            [1, "instant buy",                                    598, 'reply',  '1800000000000000003', 'public', []],
            [2, "the eras tour is officially over and i don't know what to do with myself", 480, 'tweet', null, 'public', ['metrics' => ['likes' => 1200000, 'retweets' => 250000]]],
            [1, "RT @taylorswift13: the eras tour is officially over...", 470, 'retweet', '1800000000000000005', 'public', ['retweeted_status_id' => '1800000000000000005']],
            [0, "Starship IFT-12 nominal. Ship caught.",          360, 'tweet',  null, 'public', ['metrics' => ['likes' => 980000, 'retweets' => 110000]]],
            [3, "The remake nobody asked for is finally here.",   300, 'quote',  '1800000000000000003', 'public', ['quoted_status_id' => '1800000000000000003']],
            [1, "looking for a senior backend role, php/laravel, remote. DMs open.", 240, 'tweet', null, 'public', ['metrics' => ['likes' => 47, 'retweets' => 8]]],
            [2, "thinking about doing a stripped-down acoustic tour next year", 180, 'tweet', null, 'public', ['metrics' => ['likes' => 880000]]],
            [1, "yes please",                                     179, 'reply',  '1800000000000000010', 'public', []],
            [0, "X is now the everything app",                    120, 'tweet',  null, 'public', ['metrics' => ['likes' => 220000]]],
            [1, "hey, saw your tweet about hiring, are you still looking?", 90, 'dm', null, 'private', ['conversation_id' => 'conv_001']],
            [3, "yes, send a resume to careers@",                 88, 'dm', null, 'private', ['conversation_id' => 'conv_001']],
            [1, "sent, thanks!",                                  85, 'dm', null, 'private', ['conversation_id' => 'conv_001']],
            [0, "deleting twitter forever (this is the third time this month)", 30, 'tweet', null, 'public', ['metrics' => ['likes' => 53000]]],
        ];

        $seq = 0;
        foreach ($scenarios as [$ai, $content, $minutes, $kind, $ref, $visibility, $extra]) {
            $seq++;
            $author = $users[$ai];
            $sentAt = $now->copy()->subMinutes($minutes);

            Message::create([
                'source' => 'twitter',
                'external_id' => (string) (1_800_000_000_000_000_000 + $seq),
                'container_external_id' => null,
                'container_name' => null,
                'channel_external_id' => $extra['conversation_id'] ?? null,
                'channel_name' => isset($extra['conversation_id']) ? 'DM thread' : null,
                'visibility' => $visibility,
                'author_external_id' => $author['id'],
                'author_username' => $author['username'],
                'author_display_name' => $author['display'],
                'author_bot' => false,
                'content' => $content,
                'referenced_external_id' => $ref,
                'sent_at' => $sentAt,
                'captured_at' => $sentAt,
                'payload' => array_merge(['kind' => $kind], $extra),
            ]);
        }
    }
}
