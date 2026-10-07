<?php

namespace Tests\Feature;

use App\Models\BudgetRequest;
use App\Models\ClubMembership;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionVote;
use App\Models\ElectionVoter;
use App\Models\Event;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkflowIntegrityTest extends TestCase
{
    private function role(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    public function test_fixed_passwords_do_not_bypass_database_hashes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['student' => 'Bcp@Test2026!', 'club_adviser' => 'Bcp@Adviser2026!', 'ssc' => 'Bcp@SSC2026!', 'admin' => 'Bcp@Admin2026!'] as $role => $oldPassword) {
            $user = $this->role($role);
            $user->update(['password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
            $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => $oldPassword])->assertUnprocessable();
            $this->post('/login', ['username' => $user->username, 'password' => $oldPassword])->assertSessionHasErrors('password');
            $this->assertGuest();
        }
    }

    public function test_inactive_accounts_cannot_continue_existing_sessions(): void
    {
        $user = $this->role('student');
        $user->update(['status' => 'Inactive']);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_event_requires_sequential_reviews(): void
    {
        $event = Event::firstOrFail();
        $event->update(['status' => 'Pending SSC']);
        $this->actingAs($this->role('admin'))->post("/events/{$event->id}/approve-admin")->assertStatus(409);
        $this->actingAs($this->role('ssc'))->post("/events/{$event->id}/endorse-ssc")->assertRedirect();
        $this->actingAs($this->role('admin'))->post("/events/{$event->id}/approve-admin")->assertRedirect();
        $this->assertSame('Approved', $event->fresh()->status);
        $this->post("/events/{$event->id}/approve-admin")->assertStatus(409);
    }

    public function test_admin_submission_requires_adviser_before_ssc(): void
    {
        $this->actingAs($this->role('admin'))->post('/events', ['club_id' => 1, 'title' => 'Review required', 'event_date' => now()->addDay()->toDateTimeString(), 'venue' => 'Review venue'])->assertRedirect();
        $event = Event::where('title', 'Review required')->firstOrFail();
        $this->assertSame('Pending Adviser', $event->status);
        $this->actingAs($this->role('ssc'))->post("/events/{$event->id}/endorse-ssc")->assertStatus(409);
        $this->actingAs($this->role('club_adviser'))->post("/events/{$event->id}/endorse-adviser")->assertRedirect();
        $this->assertSame('Pending SSC', $event->fresh()->status);
    }

    public function test_budget_cannot_be_paid_before_approval_or_twice(): void
    {
        $budget = BudgetRequest::firstOrFail();
        $budget->update(['status' => 'Pending SSC']);
        $this->actingAs($this->role('admin'))->post("/budgets/{$budget->id}/disburse", ['disbursement_reference' => 'PAYMENT'])->assertStatus(409);
        $this->actingAs($this->role('ssc'))->post("/budgets/{$budget->id}/endorse-ssc", ['recommended_amount' => 100])->assertRedirect();
        $this->actingAs($this->role('admin'))->post("/budgets/{$budget->id}/approve-admin", ['final_approved_amount' => 100])->assertRedirect();
        $this->post("/budgets/{$budget->id}/disburse", ['disbursement_reference' => 'PAYMENT'])->assertRedirect();
        $this->post("/budgets/{$budget->id}/disburse", ['disbursement_reference' => 'AGAIN'])->assertStatus(409);
    }

    public function test_membership_requires_all_three_roles(): void
    {
        $membership = ClubMembership::where('user_id', $this->role('student')->id)->firstOrFail();
        $membership->update(['status' => 'Pending', 'adviser_review' => 'Pending Adviser', 'ssc_review' => 'Pending SSC']);
        $this->actingAs($this->role('admin'))->post("/roster/{$membership->id}/approve-admin")->assertStatus(409);
        $this->actingAs($this->role('ssc'))->post("/roster/{$membership->id}/approve-ssc")->assertStatus(409);
        $this->actingAs($this->role('club_adviser'))->post("/roster/{$membership->id}/endorse-adviser")->assertRedirect();
        $this->actingAs($this->role('ssc'))->post("/roster/{$membership->id}/approve-ssc")->assertRedirect();
        $this->assertSame('Pending', $membership->fresh()->status);
        $this->actingAs($this->role('admin'))->post("/roster/{$membership->id}/approve-admin")->assertRedirect();
        $this->assertSame('Active', $membership->fresh()->status);
    }

    public function test_student_pages_and_metrics_are_scoped(): void
    {
        $student = $this->role('student');
        $this->actingAs($student)->get('/students')->assertInertia(fn (Assert $page) => $page->has('students.data', 1)->where('students.data.0.user_id', $student->id));
        $this->get('/budgets')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/clubs')->assertInertia(fn (Assert $page) => $page->has('clubs.data', 2));
    }

    public function test_announcement_publishing_uses_real_schema(): void
    {
        $this->actingAs($this->role('admin'))->post('/announcements', ['title' => 'Notice', 'content' => 'Published content', 'target_group' => 'All', 'priority' => 'Important'])->assertRedirect();
        $this->assertDatabaseHas('org_announcements', ['title' => 'Notice', 'author_id' => $this->role('admin')->id]);
        $this->actingAs($this->role('student'))->get('/announcements')->assertOk();
    }

    public function test_attendance_rejects_non_students_and_duplicates(): void
    {
        $event = Event::firstOrFail();
        $this->actingAs($this->role('admin'))->postJson('/attendance/scan', ['event_id' => $event->id, 'user_id' => $this->role('admin')->id])->assertNotFound();
        $data = ['event_id' => $event->id, 'user_id' => $this->role('student')->id];
        $this->postJson('/attendance/scan', $data)->assertOk();
        $this->postJson('/attendance/scan', $data)->assertStatus(409);
    }

    public function test_role_module_pages_render(): void
    {
        foreach (['student', 'club_adviser', 'ssc', 'admin'] as $role) {
            $this->actingAs($this->role($role));
            foreach (['dashboard', 'students', 'clubs', 'achievements', 'events', 'roster', 'attendance', 'elections', 'announcements'] as $module) {
                $this->get('/'.$module)->assertOk();
            }
            $this->get('/budgets')->assertStatus($role === 'student' ? 403 : 200);
        }
    }

    public function test_failed_history_write_rolls_back_event_change(): void
    {
        $event = Event::firstOrFail();
        $event->update(['status' => 'Pending SSC']);
        DB::statement("CREATE TRIGGER reject_history BEFORE INSERT ON workflow_history BEGIN SELECT RAISE(ABORT, 'History unavailable'); END");
        $this->actingAs($this->role('ssc'))->post('/events/'.$event->id.'/endorse-ssc')->assertStatus(500);
        $this->assertSame('Pending SSC', $event->fresh()->status);
    }

    public function test_ballot_is_validated_and_recorded_once(): void
    {
        $this->actingAs($this->role('admin'))->post('/elections', ['club_id' => 1, 'title' => 'Election', 'starts_at' => now()->subHour()->toDateTimeString(), 'closes_at' => now()->addHour()->toDateTimeString()])->assertRedirect();
        $election = Election::firstOrFail();
        $this->post("/elections/{$election->id}/candidates", ['name' => 'Candidate One', 'position' => 'President'])->assertRedirect();
        $this->post("/elections/{$election->id}/candidates", ['name' => 'Candidate Two', 'position' => 'President'])->assertRedirect();
        $ids = ElectionCandidate::pluck('id')->all();
        $this->actingAs($this->role('student'))->postJson("/elections/{$election->id}/vote", ['votes' => $ids])->assertUnprocessable();
        $this->assertSame(0, ElectionVoter::count());
        $this->post("/elections/{$election->id}/vote", ['votes' => [$ids[0]]])->assertRedirect();
        $this->assertSame(1, ElectionVote::count());
        $this->post("/elections/{$election->id}/vote", ['votes' => [$ids[1]]])->assertRedirect();
        $this->assertSame(1, ElectionVote::count());
        $this->get("/elections/{$election->id}")->assertOk();
    }
}
