<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Event;
use App\Models\WorkflowHistory;
use App\Support\VisibleRecords;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $role = $user->role;

        $query = VisibleRecords::events($user)->with(['club', 'creator']);

        if ($request->filled('status') && $role !== 'student') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%");
            });
        }

        $events = $query->orderBy('id', 'desc')->get();

        // Detect venue collisions with time overlap
        $eventsWithConflicts = $events->map(function ($ev) use ($events) {
            if (in_array($ev->status, ['Rejected', 'Returned'])) {
                $ev->has_conflict = false;
                return $ev;
            }

            $evStart = Carbon::parse($ev->event_date);
            $evEnd = $ev->end_time ? Carbon::parse($ev->end_time) : $evStart->copy()->addHours(3);
            $evVenue = strtolower(trim($ev->venue));

            $conflict = $events->first(function ($other) use ($ev, $evVenue, $evStart, $evEnd) {
                if ($other->id === $ev->id || in_array($other->status, ['Rejected', 'Returned'])) {
                    return false;
                }
                if (strtolower(trim($other->venue)) !== $evVenue) {
                    return false;
                }
                $otherStart = Carbon::parse($other->event_date);
                $otherEnd = $other->end_time ? Carbon::parse($other->end_time) : $otherStart->copy()->addHours(3);

                return $evStart < $otherEnd && $evEnd > $otherStart;
            });

            $ev->has_conflict = (bool) $conflict;
            if ($conflict) {
                $ev->conflicting_with = $conflict->title;
            }

            return $ev;
        });

        // Available clubs for creating events
        $clubs = [];
        if ($role === 'club_adviser') {
            $clubs = Club::where('adviser_user_id', $user->id)->where('status', 'Active')->get();
        } elseif (in_array($role, ['ssc', 'admin'])) {
            $clubs = Club::where('status', 'Active')->orderBy('name')->get();
        }

        return Inertia::render('Events/Index', [
            'events' => $eventsWithConflicts,
            'clubs' => $clubs,
            'filters' => $request->only(['status', 'search']),
            'metrics' => [
                'total' => VisibleRecords::events($user)->count(),
                'approved' => VisibleRecords::events($user)->where('status', 'Approved')->count(),
                'pending_ssc' => VisibleRecords::events($user)->where('status', 'Pending SSC')->count(),
                'pending_admin' => VisibleRecords::events($user)->where('status', 'Pending Admin')->count(),
            ],
        ]);
    }

    public function checkConflict(Request $request)
    {
        $validated = $request->validate([
            'venue' => 'required|string',
            'event_date' => 'required|date',
            'end_time' => 'nullable|date',
            'ignore_id' => 'nullable|integer',
        ]);

        $venue = strtolower(trim($validated['venue']));
        $start = Carbon::parse($validated['event_date']);
        $end = !empty($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $start->copy()->addHours(3);

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $intervalSql = $isSqlite ? "COALESCE(end_time, datetime(event_date, '+3 hours'))" : "COALESCE(end_time, DATE_ADD(event_date, INTERVAL 3 HOUR))";

        $conflict = Event::where('status', '!=', 'Rejected')
            ->where('status', '!=', 'Returned')
            ->when(!empty($validated['ignore_id']), fn ($q) => $q->where('id', '!=', $validated['ignore_id']))
            ->whereRaw('LOWER(TRIM(venue)) = ?', [$venue])
            ->where(function ($q) use ($start, $end, $intervalSql) {
                $q->where(function ($sub) use ($start, $end, $intervalSql) {
                    $sub->where('event_date', '<', $end->toDateTimeString())
                        ->where(DB::raw($intervalSql), '>', $start->toDateTimeString());
                });
            })
            ->first();

        if ($conflict) {
            $startFormatted = $conflict->event_date ? Carbon::parse($conflict->event_date)->format('M d, Y h:i A') : '';
            $endFormatted = $conflict->end_time ? Carbon::parse($conflict->end_time)->format('h:i A') : 'TBD';
            return response()->json([
                'has_conflict' => true,
                'conflict_event' => [
                    'id' => $conflict->id,
                    'title' => $conflict->title,
                    'venue' => $conflict->venue,
                    'event_date' => $startFormatted,
                    'end_time' => $endFormatted,
                    'status' => $conflict->status,
                ],
                'message' => "Schedule Conflict Detected: '{$conflict->title}' is already scheduled at '{$conflict->venue}' from {$startFormatted} to {$endFormatted}.",
            ]);
        }

        return response()->json([
            'has_conflict' => false,
            'message' => 'Venue and schedule are clear! No conflicting campus activities found.',
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if ($user->role === 'student') {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'event_date' => 'required|date',
            'end_time' => 'nullable|date|after_or_equal:event_date',
            'venue' => 'required|string|max:150',
            'expected_attendees' => 'nullable|integer|min:0',
            'event_type' => 'nullable|string|max:50',
            'audience_type' => 'nullable|in:Inclusive,Exclusive',
        ]);

        // Club adviser can only create for their assigned club
        if ($user->role === 'club_adviser') {
            if (! Club::whereKey($validated['club_id'])->where('adviser_user_id', $user->id)->exists()) {
                abort(403, 'You can only create events for your assigned organization.');
            }
        }

        // Real-time conflict validation check
        $venue = strtolower(trim($validated['venue']));
        $start = Carbon::parse($validated['event_date']);
        $end = !empty($validated['end_time']) ? Carbon::parse($validated['end_time']) : $start->copy()->addHours(3);

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $intervalSql = $isSqlite ? "COALESCE(end_time, datetime(event_date, '+3 hours'))" : "COALESCE(end_time, DATE_ADD(event_date, INTERVAL 3 HOUR))";

        $conflict = Event::where('status', '!=', 'Rejected')
            ->where('status', '!=', 'Returned')
            ->whereRaw('LOWER(TRIM(venue)) = ?', [$venue])
            ->where(function ($q) use ($start, $end, $intervalSql) {
                $q->where(function ($sub) use ($start, $end, $intervalSql) {
                    $sub->where('event_date', '<', $end->toDateTimeString())
                        ->where(DB::raw($intervalSql), '>', $start->toDateTimeString());
                });
            })
            ->first();

        if ($conflict) {
            $confStart = $conflict->event_date ? Carbon::parse($conflict->event_date)->format('M d, Y h:i A') : '';
            $confEnd = $conflict->end_time ? Carbon::parse($conflict->end_time)->format('h:i A') : '';
            throw ValidationException::withMessages([
                'venue' => "Venue Conflict: '{$conflict->venue}' is already booked for '{$conflict->title}' ({$confStart} - {$confEnd}). Please adjust time or venue.",
            ]);
        }

        $initialStatus = $user->role === 'club_adviser' ? 'Pending SSC' : 'Pending Adviser';

        $event = Event::create([
            'club_id' => $validated['club_id'],
            'event_type' => $validated['event_type'] ?? 'Club Activity',
            'audience_type' => $validated['audience_type'] ?? 'Inclusive',
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'event_date' => $validated['event_date'],
            'end_time' => $end,
            'venue' => $validated['venue'],
            'expected_attendees' => $validated['expected_attendees'] ?? 0,
            'status' => $initialStatus,
            'created_by' => $user->id,
        ]);

        WorkflowHistory::create([
            'module' => 'events',
            'record_id' => $event->id,
            'from_status' => 'None',
            'to_status' => $initialStatus,
            'action' => 'Event Submission',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => 'Initial submission',
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'EVENT_CREATED',
            'details' => "Created event: {$event->title} (Status: {$initialStatus}, Scope: {$event->audience_type})",
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('events.index')->with('success', 'Event requisition submitted successfully!');
    }

    public function endorseAdviser(Request $request, Event $event)
    {
        $user = $request->user();
        abort_unless($user->role === 'club_adviser' && Club::whereKey($event->club_id)->where('adviser_user_id', $user->id)->exists(), 403);
        abort_unless($event->status === 'Pending Adviser', 409);
        $event->update(['status' => 'Pending SSC']);
        WorkflowHistory::create([
            'module' => 'events', 'record_id' => $event->id,
            'from_status' => 'Pending Adviser', 'to_status' => 'Pending SSC',
            'action' => 'Adviser Endorsement', 'performed_by' => $user->id,
            'actor_role' => $user->role, 'remarks' => $request->input('remarks'),
        ]);

        return back()->with('success', 'Endorsed for SSC review.');
    }

    public function endorseSsc(Request $request, Event $event)
    {
        $user = Auth::user();
        if ($user->role !== 'ssc') {
            abort(403, 'Only SSC officers can perform SSC review.');
        }

        abort_unless($event->status === 'Pending SSC', 409, 'Event is not awaiting SSC review.');
        $previous = $event->status;
        $event->update([
            'status' => 'Pending Admin',
            'endorsement_notes' => $request->input('notes', 'Endorsed by SSC'),
        ]);

        WorkflowHistory::create([
            'module' => 'events',
            'record_id' => $event->id,
            'from_status' => $previous,
            'to_status' => 'Pending Admin',
            'action' => 'SSC Endorsement',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => $request->input('remarks', 'Endorsed by SSC'),
        ]);

        return back()->with('success', 'Event endorsed and forwarded to Admin clearance.');
    }

    public function approveAdmin(Request $request, Event $event)
    {
        $user = Auth::user();
        if ($user->role !== 'admin') {
            abort(403, 'Only System Administrators can grant final event clearance.');
        }

        abort_unless($event->status === 'Pending Admin', 409, 'Event is not awaiting admin clearance.');
        $previous = $event->status;
        $event->update(['status' => 'Approved']);

        WorkflowHistory::create([
            'module' => 'events',
            'record_id' => $event->id,
            'from_status' => $previous,
            'to_status' => 'Approved',
            'action' => 'Admin Approval',
            'performed_by' => $user->id,
            'actor_role' => 'admin',
            'remarks' => $request->input('remarks', 'Final clearance granted by Admin'),
        ]);

        return back()->with('success', 'Event approved and officially published to campus calendar!');
    }

    public function reject(Request $request, Event $event)
    {
        $user = Auth::user();
        if (! in_array($user->role, ['ssc', 'admin'])) {
            abort(403, 'Unauthorized.');
        }

        abort_unless($event->status === ($user->role === 'ssc' ? 'Pending SSC' : 'Pending Admin'), 409);
        $previous = $event->status;
        $event->update([
            'status' => 'Rejected',
            'rejection_note' => $request->input('reason', 'Rejected during review.'),
        ]);

        WorkflowHistory::create([
            'module' => 'events',
            'record_id' => $event->id,
            'from_status' => $previous,
            'to_status' => 'Rejected',
            'action' => 'Rejection',
            'performed_by' => $user->id,
            'actor_role' => $user->role,
            'remarks' => $request->input('reason', 'Rejected during review.'),
        ]);

        return back()->with('info', 'Event returned/rejected.');
    }
}
