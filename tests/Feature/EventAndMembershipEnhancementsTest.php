<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventAndMembershipEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function role(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    public function test_conflict_checker_identifies_overlapping_venue_schedules(): void
    {
        $admin = $this->role('admin');
        $start = now()->addDays(3)->setTime(10, 0);
        $end = now()->addDays(3)->setTime(13, 0);

        Event::create([
            'club_id' => 1,
            'title' => 'First Tech Expo',
            'event_date' => $start,
            'end_time' => $end,
            'venue' => 'Main Auditorium',
            'status' => 'Approved',
            'created_by' => $admin->id,
            'audience_type' => 'Inclusive',
        ]);

        // Conflict check via API
        $response = $this->actingAs($admin)->postJson('/events/check-conflict', [
            'venue' => 'Main Auditorium',
            'event_date' => $start->copy()->addHour()->toDateTimeString(), // 11:00 AM (overlaps with 10:00 - 13:00)
            'end_time' => $end->copy()->addHour()->toDateTimeString(),
        ]);

        $response->assertOk();
        $response->assertJson([
            'has_conflict' => true,
        ]);

        // Different venue should be clear
        $clearResponse = $this->actingAs($admin)->postJson('/events/check-conflict', [
            'venue' => 'AVR Room 2',
            'event_date' => $start->toDateTimeString(),
            'end_time' => $end->toDateTimeString(),
        ]);

        $clearResponse->assertOk();
        $clearResponse->assertJson([
            'has_conflict' => false,
        ]);
    }

    public function test_exclusive_event_rejects_non_club_member_qr_scan(): void
    {
        $admin = $this->role('admin');
        $student = $this->role('student'); // Juan Santos

        // Ensure student is NOT an active member of club #2
        ClubMembership::where('club_id', 2)->where('user_id', $student->id)->delete();

        $exclusiveEvent = Event::create([
            'club_id' => 2,
            'title' => 'Exclusive Club Summit',
            'event_date' => now()->addDay(),
            'end_time' => now()->addDay()->addHours(2),
            'venue' => 'Room 301',
            'status' => 'Approved',
            'created_by' => $admin->id,
            'audience_type' => 'Exclusive',
        ]);

        $response = $this->actingAs($admin)->postJson('/attendance/scan', [
            'event_id' => $exclusiveEvent->id,
            'student_number' => $student->username,
            'method' => 'QR',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'exclusive_denied' => true,
        ]);
        $this->assertDatabaseMissing('attendance_logs', [
            'event_id' => $exclusiveEvent->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_inclusive_event_allows_all_active_students_to_check_in(): void
    {
        $admin = $this->role('admin');
        $student = $this->role('student');

        $inclusiveEvent = Event::create([
            'club_id' => 1,
            'title' => 'Campus Open Hackathon',
            'event_date' => now()->addDay(),
            'end_time' => now()->addDay()->addHours(4),
            'venue' => 'Grand Pavilion',
            'status' => 'Approved',
            'created_by' => $admin->id,
            'audience_type' => 'Inclusive',
        ]);

        $response = $this->actingAs($admin)->postJson('/attendance/scan', [
            'event_id' => $inclusiveEvent->id,
            'student_number' => $student->username,
            'method' => 'QR',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
        $this->assertDatabaseHas('attendance_logs', [
            'event_id' => $inclusiveEvent->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_adviser_provides_endorsement_letter_and_forwards_to_ssc(): void
    {
        $student = $this->role('student');
        $adviser = $this->role('club_adviser');
        $club = Club::where('adviser_user_id', $adviser->id)->firstOrFail();
        ClubMembership::where('club_id', $club->id)->where('user_id', $student->id)->delete();

        // 1. Student submits application without endorsement letter
        $this->actingAs($student)->post('/roster/apply', [
            'club_id' => $club->id,
            'letter_intent' => 'My statement of intent and passion for student development.',
        ])->assertRedirect();

        $membership = ClubMembership::where('club_id', $club->id)->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('Pending Adviser', $membership->adviser_review);
        $this->assertNull($membership->letter_endorsement);

        // 2. Adviser provides the official letter of endorsement
        $endorsementDoc = 'OFFICIAL MEMORANDUM OF FACULTY ENDORSEMENT: Student demonstrates leadership competencies and academic integrity.';
        $this->actingAs($adviser)->post("/roster/{$membership->id}/endorse-adviser", [
            'letter_endorsement' => $endorsementDoc,
            'notes' => 'Faculty vetting complete.',
        ])->assertRedirect();

        $fresh = $membership->fresh();
        $this->assertSame('Endorsed', $fresh->adviser_review);
        $this->assertSame('Pending SSC', $fresh->ssc_review);
        $this->assertSame($endorsementDoc, $fresh->letter_endorsement);

        // 3. SSC reviews and approves
        $ssc = $this->role('ssc');
        $this->actingAs($ssc)->post("/roster/{$membership->id}/approve-ssc")->assertRedirect();
        $this->assertSame('Approved', $membership->fresh()->ssc_review);
    }
}
