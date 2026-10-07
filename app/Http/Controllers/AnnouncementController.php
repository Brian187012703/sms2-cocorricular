<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\OrgAnnouncement;
use App\Support\VisibleRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $role = $user->role;

        $query = VisibleRecords::announcements($user)->with(['club', 'author']);

        $announcements = $query->orderBy('id', 'desc')->paginate(15);

        $clubs = in_array($role, ['ssc', 'admin', 'club_adviser'])
            ? Club::where('status', 'Active')->when($role === 'club_adviser', fn ($q) => $q->where('adviser_user_id', $user->id))->orderBy('name')->get()
            : [];

        return Inertia::render('Announcements/Index', [
            'announcements' => $announcements,
            'clubs' => $clubs,
            'canPost' => in_array($role, ['ssc', 'admin', 'club_adviser']),
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if ($user->role === 'student') {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'target_group' => 'required|string|in:All,Students,Advisers,Faculty,SSC',
            'club_id' => 'nullable|exists:clubs,id',
            'priority' => 'nullable|string|in:Normal,Important,Urgent',
        ]);

        if ($user->role === 'club_adviser') {
            abort_unless(Club::whereKey($validated['club_id'] ?? null)->where('adviser_user_id', $user->id)->exists(), 403);
        }

        $announcement = OrgAnnouncement::create([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'target_group' => $validated['target_group'],
            'club_id' => $validated['club_id'] ?? null,
            'priority' => $validated['priority'] ?? 'Normal',
            'author_id' => $user->id,
            'status' => 'Published',
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'ANNOUNCEMENT_POSTED',
            'details' => "Posted announcement: {$announcement->title}",
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'Announcement published successfully!');
    }
}
