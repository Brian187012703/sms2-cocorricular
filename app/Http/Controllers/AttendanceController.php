<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use App\Support\VisibleRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = VisibleRecords::attendance($user)->with(['event.club', 'user.student', 'logger']);

        // Students can only see their own attendance
        if ($user->role === 'student') {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('event_id')) {
            $query->where('event_id', $request->event_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('user', function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%")
                    ->orWhere('username', 'like', "%{$s}%");
            });
        }

        $logs = $query->orderBy('id', 'desc')->paginate(20)->withQueryString();

        $activeEvents = Event::where('status', 'Approved')
            ->with('club')
            ->when($user->role === 'club_adviser', fn ($q) => $q->whereIn('club_id', VisibleRecords::clubIds($user)))
            ->orderBy('id', 'desc')
            ->take(25)
            ->get(['id', 'club_id', 'title', 'event_date', 'end_time', 'venue', 'audience_type']);

        // For students, check which events they are eligible to attend
        $studentMemberships = $user->role === 'student'
            ? \App\Models\ClubMembership::where('user_id', $user->id)->where('status', 'Active')->pluck('club_id')->toArray()
            : [];

        $activeEventsMapped = $activeEvents->map(function ($ev) use ($user, $studentMemberships) {
            $ev->is_eligible = $user->role !== 'student' 
                || $ev->audience_type === 'Inclusive' 
                || in_array($ev->club_id, $studentMemberships);
            return $ev;
        });

        return Inertia::render('Attendance/Index', [
            'logs' => $logs,
            'events' => $activeEventsMapped,
            'filters' => $request->only(['event_id', 'search']),
            'totalScans' => VisibleRecords::attendance($user)->count(),
            'uniqueEvents' => VisibleRecords::attendance($user)->distinct('event_id')->count('event_id'),
        ]);
    }

    public function scanner()
    {
        $user = Auth::user();
        if ($user->role === 'student') {
            abort(403, 'Students are not authorized to operate the official attendance scanner.');
        }

        $events = Event::where('status', 'Approved')
            ->with('club')
            ->when($user->role === 'club_adviser', fn ($q) => $q->whereIn('club_id', VisibleRecords::clubIds($user)))
            ->orderBy('id', 'desc')
            ->take(20)
            ->get(['id', 'club_id', 'title', 'event_date', 'end_time', 'venue', 'audience_type']);

        return Inertia::render('Attendance/Scanner', [
            'events' => $events,
        ]);
    }

    public function recordScan(Request $request)
    {
        $operator = Auth::user();
        if ($operator->role === 'student') {
            return response()->json(['success' => false, 'message' => 'Unauthorized operator.'], 403);
        }

        $validated = $request->validate([
            'event_id' => 'required|exists:events,id',
            'student_number' => 'nullable|string',
            'user_id' => 'nullable|exists:users,id',
            'method' => 'nullable|string|in:QR,Manual,RFID',
        ]);

        $event = Event::with('club')->whereKey($validated['event_id'])->lockForUpdate()->firstOrFail();
        abort_unless($event->status === 'Approved', 422, 'Attendance requires an approved event.');
        if ($operator->role === 'club_adviser') {
            abort_unless($event->club?->adviser_user_id === $operator->id, 403);
        }
        // Find user by user_id or student_number
        $targetUser = null;
        if (! empty($validated['user_id'])) {
            $targetUser = User::find($validated['user_id']);
        } elseif (! empty($validated['student_number'])) {
            $student = Student::where('student_number', trim($validated['student_number']))->first();
            if ($student && $student->user_id) {
                $targetUser = User::find($student->user_id);
            } else {
                $targetUser = User::where('username', trim($validated['student_number']))->first();
            }
        }

        if (! $targetUser || $targetUser->role !== 'student' || $targetUser->status !== 'Active') {
            return response()->json([
                'success' => false,
                'message' => 'Student record not found in system database.',
            ], 404);
        }

        // Exclusivity Check: If Exclusive, attendee MUST be an active member of the club
        if ($event->audience_type === 'Exclusive' && $event->club_id) {
            $isMember = \App\Models\ClubMembership::where('club_id', $event->club_id)
                ->where('user_id', $targetUser->id)
                ->where('status', 'Active')
                ->exists();

            if (! $isMember) {
                $clubName = $event->club ? $event->club->name : 'hosting club';
                return response()->json([
                    'success' => false,
                    'exclusive_denied' => true,
                    'message' => "Access Restricted: This event is EXCLUSIVE to enrolled members of {$clubName}. Attendee {$targetUser->first_name} {$targetUser->last_name} is not an enrolled member.",
                    'student' => [
                        'name' => "{$targetUser->first_name} {$targetUser->last_name}",
                        'student_number' => $targetUser->username,
                    ],
                ], 403);
            }
        }

        // Duplicate scan check
        $existing = AttendanceLog::where('event_id', $validated['event_id'])
            ->where('user_id', $targetUser->id)
            ->first();

        if ($existing) {
            $timeFormatted = $existing->check_in ? $existing->check_in->format('h:i A') : 'earlier';

            return response()->json([
                'success' => false,
                'duplicate' => true,
                'message' => "Duplicate scan: {$targetUser->first_name} {$targetUser->last_name} already checked in at {$timeFormatted}.",
                'student' => [
                    'name' => "{$targetUser->first_name} {$targetUser->last_name}",
                    'student_number' => $targetUser->username,
                    'check_in' => $timeFormatted,
                ],
            ], 409);
        }

        // Record attendance
        $log = AttendanceLog::create([
            'event_id' => $validated['event_id'],
            'user_id' => $targetUser->id,
            'check_in' => now(),
            'method' => $validated['method'] ?? 'Manual',
            'logged_by' => $operator->id,
            'status' => 'Valid',
        ]);

        AuditLog::create([
            'user_id' => $operator->id,
            'action' => 'ATTENDANCE_RECORDED',
            'details' => "Checked in {$targetUser->username} for event #{$validated['event_id']}",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Success! Checked in {$targetUser->first_name} {$targetUser->last_name}.",
            'student' => [
                'name' => "{$targetUser->first_name} {$targetUser->last_name}",
                'student_number' => $targetUser->username,
                'check_in' => now()->format('h:i A'),
            ],
        ]);
    }
}
