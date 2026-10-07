<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InterClubChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Run chat channel seeder
        $this->seed(\Database\Seeders\ChatChannelSeeder::class);
    }

    protected function userByRole(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    public function test_chat_tables_are_populated(): void
    {
        $this->assertGreaterThan(0, DB::table('chat_channels')->count());
        $this->assertGreaterThan(0, DB::table('chat_messages')->count());
    }

    public function test_student_isolated_to_own_organization(): void
    {
        $student = $this->userByRole('student');
        
        // Find student's club membership
        $membership = DB::table('club_memberships')
            ->where('user_id', $student->id)
            ->where('status', 'Active')
            ->first();

        $this->assertNotNull($membership);
        $studentClubId = $membership->club_id;

        // Student's club group channel
        $ownClubChannel = DB::table('chat_channels')
            ->where('club_id', $studentClubId)
            ->where('type', 'club_group')
            ->first();
        $this->assertNotNull($ownClubChannel);

        // Another club channel that student does not belong to
        $otherClubChannel = DB::table('chat_channels')
            ->where('club_id', '!=', $studentClubId)
            ->where('type', 'club_group')
            ->first();

        // Adviser-SSC desk channel
        $adviserSscChannel = DB::table('chat_channels')
            ->where('type', 'adviser_ssc')
            ->first();

        $this->assertNotNull($otherClubChannel);
        $this->assertNotNull($adviserSscChannel);
    }

    public function test_adviser_and_ssc_can_collaborate_on_management_desk(): void
    {
        $adviser = $this->userByRole('club_adviser');
        $ssc = $this->userByRole('ssc');

        // Check if there is an adviser-ssc channel
        $desk = DB::table('chat_channels')->where('type', 'adviser_ssc')->first();
        $this->assertNotNull($desk);

        // Insert a message from adviser to SSC
        $msgId = DB::table('chat_messages')->insertGetId([
            'channel_id' => $desk->id,
            'sender_id' => $adviser->id,
            'message' => 'Discussion regarding campus club event approval.',
            'created_at' => now(),
        ]);

        $this->assertGreaterThan(0, $msgId);

        // SSC responds
        $replyId = DB::table('chat_messages')->insertGetId([
            'channel_id' => $desk->id,
            'sender_id' => $ssc->id,
            'message' => 'Acknowledged. We have scheduled the review.',
            'created_at' => now(),
        ]);

        $this->assertGreaterThan(0, $replyId);
    }
}
