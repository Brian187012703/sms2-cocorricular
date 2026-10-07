<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\WorkflowHistory;
use App\Support\VisibleRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class RosterController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $role = $user->role;

        $query = VisibleRecords::memberships($user)->with(['club', 'user.student']);

        if ($role === 'student') {
            $query->where('user_id', $user->id);
        } elseif ($role === 'club_adviser') {
            $query->whereIn('club_id', Club::where('adviser_user_id', $user->id)->select('id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('club_id') && in_array($role, ['ssc', 'admin'])) {
            $query->where('club_id', $request->club_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('user', function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%")
                    ->orWhere('username', 'like', "%{$s}%");
            });
        }

        $memberships = $query->orderBy('id', 'desc')->paginate(20)->withQueryString();

        $availableClubs = Club::where('status', 'Active')->orderBy('name')->get(['id', 'name', 'code', 'category']);

        return Inertia::render('Roster/Index', [
            'memberships' => $memberships,
            'availableClubs' => $availableClubs,
            'filters' => $request->only(['status', 'club_id', 'search']),
            'metrics' => [
                'total_members' => VisibleRecords::memberships($user)->where('status', 'Active')->count(),
                'pending_adviser' => VisibleRecords::memberships($user)->where('status', 'Pending')->where('adviser_review', 'Pending Adviser')->count(),
                'pending_ssc' => VisibleRecords::memberships($user)->where('status', 'Pending')->where('adviser_review', 'Endorsed')->count(),
            ],
        ]);
    }

    public function apply(Request $request)
    {
        $user = Auth::user();
        if ($user->role !== 'student') {
            return back()->with('error', 'Only students can submit membership applications.');
        }

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'letter_intent' => 'required|string|max:2000',
        ]);

        $club = Club::whereKey($validated['club_id'])->lockForUpdate()->firstOrFail();
        abort_unless($club->status === 'Active', 422, 'This club is not accepting applications.');
        $existing = ClubMembership::where('club_id', $validated['club_id'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['Active', 'Pending'])
            ->first();

        if ($existing) {
            return back()->with('error', 'You already have an active or pending membership in this organization.');
        }

        $membership = ClubMembership::updateOrCreate(['club_id' => $validated['club_id'], 'user_id' => $user->id], [
            'club_id' => $validated['club_id'],
            'user_id' => $user->id,
            'role' => 'Member',
            'status' => 'Pending',
            'adviser_review' => 'Pending Adviser',
            'ssc_review' => 'Pending SSC',
            'admin_review' => 'Pending Admin',
            'approved_by' => null,
            'letter_intent' => $validated['letter_intent'],
            'letter_endorsement' => null, // To be provided exclusively by Faculty Adviser
            'joined_at' => now(),
        ]);

        WorkflowHistory::create([
            'module' => 'membership',
            'record_id' => $membership->id,
            'from_status' => 'None',
            'to_status' => 'Pending Adviser',
            'action' => 'Application Submitted',
            'performed_by' => $user->id,
            'actor_role' => 'student',
            'remarks' => 'Student submitted membership application. Awaiting Faculty Adviser review and endorsement.',
        ]);

        return back()->with('success', 'Application submitted successfully! Your organization Faculty Adviser will review and issue the official Letter of Endorsement.');
    }

    public function endorseAdviser(Request $request, ClubMembership $membership)
    {
        $user = Auth::user();
        if ($user->role === 'club_adviser') {
            if (! Club::whereKey($membership->club_id)->where('adviser_user_id', $user->id)->exists()) {
                abort(403, 'Unauthorized. You can only endorse applicants for your assigned organization.');
            }
        } else {
            abort(403, 'Unauthorized.');
        }

        abort_unless($membership->status === 'Pending' && $membership->adviser_review === 'Pending Adviser', 409);

        $validated = $request->validate([
            'letter_endorsement' => 'nullable|string|max:3000',
            'notes' => 'nullable|string|max:500',
        ]);

        $endorsementText = !empty($validated['letter_endorsement'])
            ? $validated['letter_endorsement']
            : 'Official Faculty Recommendation & Endorsement Letter issued by Faculty Adviser.';

        $membership->update([
            'adviser_review' => 'Endorsed',
            'letter_endorsement' => $endorsementText,
            'review_notes' => $validated['notes'] ?? 'Endorsed with official Faculty Recommendation Letter',
        ]);

        WorkflowHistory::create([
            'module' => 'membership',
            'record_id' => $membership->id,
            'from_status' => 'Pending Adviser',
            'to_status' => 'Endorsed (Pending SSC)',
            'action' => 'Adviser Endorsement',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => 'Faculty Adviser endorsed student with official Letter of Endorsement and forwarded to SSC for clearance.',
        ]);

        return back()->with('success', 'Official Letter of Endorsement provided! Application forwarded to Supreme Student Council (SSC) for approval.');
    }

    public function approveSsc(Request $request, ClubMembership $membership)
    {
        $user = Auth::user();
        if ($user->role !== 'ssc') {
            abort(403, 'Only SSC officers can perform SSC review.');
        }

        abort_unless($membership->status === 'Pending' && $membership->adviser_review === 'Endorsed' && $membership->ssc_review === 'Pending SSC', 409);
        $membership->update([
            'ssc_review' => 'Approved',
            'status' => 'Pending',
        ]);

        WorkflowHistory::create([
            'module' => 'membership',
            'record_id' => $membership->id,
            'from_status' => 'Endorsed',
            'to_status' => 'Pending Admin',
            'action' => 'SSC Review',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => 'Membership forwarded for admin clearance',
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'MEMBERSHIP_REVIEWED',
            'details' => "Reviewed member #{$membership->user_id} in club #{$membership->club_id}",
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'Membership reviewed and forwarded for admin clearance.');
    }

    public function approveAdmin(Request $request, ClubMembership $membership)
    {
        $user = $request->user();
        abort_unless($user->role === 'admin', 403);
        abort_unless($membership->status === 'Pending' && $membership->adviser_review === 'Endorsed' && $membership->ssc_review === 'Approved', 409);
        $membership->update(['status' => 'Active', 'admin_review' => 'Approved', 'approved_by' => $user->id]);
        WorkflowHistory::create([
            'module' => 'membership', 'record_id' => $membership->id,
            'from_status' => 'Pending Admin', 'to_status' => 'Active',
            'action' => 'Admin Clearance', 'performed_by' => $user->id, 'actor_role' => $user->role,
            'remarks' => $request->input('remarks'),
        ]);

        return back()->with('success', 'Membership activated.');
    }

    public function reject(Request $request, ClubMembership $membership)
    {
        $user = Auth::user();
        if (! in_array($user->role, ['club_adviser', 'ssc', 'admin'])) {
            abort(403, 'Unauthorized.');
        }

        abort_unless($membership->status === 'Pending', 409);
        if ($user->role === 'club_adviser') {
            abort_unless(Club::whereKey($membership->club_id)->where('adviser_user_id', $user->id)->exists(), 403);
        }
        abort_unless(match ($user->role) {
            'club_adviser' => $membership->adviser_review === 'Pending Adviser',
            'ssc' => $membership->adviser_review === 'Endorsed' && $membership->ssc_review === 'Pending SSC',
            'admin' => $membership->ssc_review === 'Approved',
            default => false,
        }, 409);
        $previous = $membership->status;
        $membership->update([
            'status' => 'Rejected',
            'review_notes' => $request->input('reason', 'Application rejected during review.'),
        ]);

        WorkflowHistory::create([
            'module' => 'membership',
            'record_id' => $membership->id,
            'from_status' => $previous,
            'to_status' => 'Rejected',
            'action' => 'Application Rejected',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => $request->input('reason', 'Application rejected during review.'),
        ]);

        return back()->with('info', 'Application rejected.');
    }
}
