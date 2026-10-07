<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChatChannelSeeder extends Seeder
{
    public function run(): void
    {
        $clubs = DB::table('clubs')->whereNull('deleted_at')->orderBy('id')->get();
        $sscUser = DB::table('users')->where('role', 'ssc')->first();
        $sscUserId = $sscUser ? $sscUser->id : null;

        foreach ($clubs as $club) {
            $advId = $club->adviser_user_id ?: null;

            // 1. Club Group Channel (for active students and adviser)
            $existingGroup = DB::table('chat_channels')
                ->where('type', 'club_group')
                ->where('club_id', $club->id)
                ->first();

            if (!$existingGroup) {
                $chanId = DB::table('chat_channels')->insertGetId([
                    'name' => "{$club->name} Club Lounge",
                    'type' => 'club_group',
                    'club_id' => $club->id,
                    'description' => 'Official organization room for active student members and faculty adviser.',
                    'created_by' => $advId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Subscribe adviser
                if ($advId) {
                    DB::table('chat_members')->insertOrIgnore([
                        'channel_id' => $chanId,
                        'user_id' => $advId,
                        'created_at' => now(),
                    ]);
                }

                // Subscribe active student members
                $members = DB::table('club_memberships')
                    ->where('club_id', $club->id)
                    ->where('status', 'Active')
                    ->pluck('user_id');

                foreach ($members as $uId) {
                    DB::table('chat_members')->insertOrIgnore([
                        'channel_id' => $chanId,
                        'user_id' => $uId,
                        'created_at' => now(),
                    ]);
                }

                // Initial welcome message from adviser
                if ($advId) {
                    DB::table('chat_messages')->insert([
                        'channel_id' => $chanId,
                        'sender_id' => $advId,
                        'message' => "Welcome {$club->name} members! This is our dedicated organization communication room. Please use this space for official announcements, project updates, and member coordination.",
                        'created_at' => now()->subDay(),
                    ]);
                }
            }

            // 2. Adviser & SSC Management Desk (for Adviser and SSC officers)
            $existingSscDesk = DB::table('chat_channels')
                ->where('type', 'adviser_ssc')
                ->where('club_id', $club->id)
                ->first();

            if (!$existingSscDesk) {
                $sscChanId = DB::table('chat_channels')->insertGetId([
                    'name' => "{$club->code} Adviser & SSC Management Desk",
                    'type' => 'adviser_ssc',
                    'club_id' => $club->id,
                    'description' => 'Executive consultation desk between faculty adviser and Supreme Student Council officers.',
                    'created_by' => $advId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($advId) {
                    DB::table('chat_members')->insertOrIgnore([
                        'channel_id' => $sscChanId,
                        'user_id' => $advId,
                        'created_at' => now(),
                    ]);
                }

                if ($sscUserId) {
                    DB::table('chat_members')->insertOrIgnore([
                        'channel_id' => $sscChanId,
                        'user_id' => $sscUserId,
                        'created_at' => now(),
                    ]);
                }

                if ($advId && $sscUserId) {
                    DB::table('chat_messages')->insert([
                        'channel_id' => $sscChanId,
                        'sender_id' => $advId,
                        'message' => "Good day SSC Council leaders. We are preparing our proposed activity calendar and project endorsements for {$club->code}.",
                        'created_at' => now()->subHours(2),
                    ]);

                    DB::table('chat_messages')->insert([
                        'channel_id' => $sscChanId,
                        'sender_id' => $sscUserId,
                        'message' => "Good day Adviser! Received and acknowledged. Please ensure all proposal documents and venue conflict checks are submitted so we can proceed with endorsement.",
                        'created_at' => now()->subHour(),
                    ]);
                }
            }
        }
    }
}
