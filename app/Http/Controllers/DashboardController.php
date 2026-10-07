<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Support\VisibleRecords;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->role;

        if ($role === 'student') {
            $metrics = [
                'primary_metric' => VisibleRecords::memberships($user)->where('status', 'Active')->count(),
                'primary_label' => 'Active Memberships',
                'secondary_metric' => VisibleRecords::attendance($user)->count(),
                'secondary_label' => 'Events Attended',
                'active_clubs' => Club::where('status', 'Active')->count(),
                'upcoming_events' => VisibleRecords::events($user)->where('status', 'Approved')->where('event_date', '>=', now()->toDateString())->count(),
                'verified_achievements' => VisibleRecords::achievements($user)->where('status', 'Verified')->count(),
                'pending_action' => VisibleRecords::memberships($user)->where('status', 'Pending')->count(),
                'pending_action_label' => 'Pending Applications',
            ];
        } elseif ($role === 'club_adviser') {
            $metrics = [
                'primary_metric' => VisibleRecords::students($user)->count(),
                'primary_label' => 'Club Members',
                'secondary_metric' => VisibleRecords::events($user)->whereIn('club_id', VisibleRecords::clubIds($user))->count(),
                'secondary_label' => 'Club Events',
                'active_clubs' => Club::where('adviser_user_id', $user->id)->count(),
                'upcoming_events' => VisibleRecords::events($user)->where('status', 'Approved')->where('event_date', '>=', now()->toDateString())->count(),
                'verified_achievements' => VisibleRecords::achievements($user)->where('status', 'Verified')->count(),
                'pending_action' => VisibleRecords::memberships($user)->where('status', 'Pending')->where('adviser_review', 'Pending Adviser')->count(),
                'pending_action_label' => 'Pending Endorsements',
            ];
        } else {
            $metrics = [
                'primary_metric' => \App\Models\Student::count(),
                'primary_label' => 'Students Enrolled',
                'secondary_metric' => Club::where('status', 'Active')->count(),
                'secondary_label' => 'Active Clubs',
                'active_clubs' => Club::where('status', 'Active')->count(),
                'upcoming_events' => VisibleRecords::events($user)->where('status', 'Approved')->where('event_date', '>=', now()->toDateString())->count(),
                'verified_achievements' => VisibleRecords::achievements($user)->where('status', 'Verified')->count(),
                'pending_action' => VisibleRecords::budgets($user)->whereIn('status', ['Pending SSC', 'Pending Admin'])->count(),
                'pending_action_label' => 'Pending Budgets',
            ];
        }

        $recent_events = VisibleRecords::events($user)->with('club')
            ->orderBy('id', 'desc')
            ->take(5)
            ->get();

        $recent_achievements = VisibleRecords::achievements($request->user())->with('submitter.student')->orderBy('id', 'desc')
            ->take(5)
            ->get();

        $featured_clubs = Club::where('status', 'Active')
            ->take(6)
            ->get();

        $announcements = VisibleRecords::announcements($user)->orderBy('id', 'desc')
            ->take(4)
            ->get();

        return Inertia::render('Dashboard', [
            'metrics' => $metrics,
            'recentEvents' => $recent_events,
            'recentAchievements' => $recent_achievements,
            'featuredClubs' => $featured_clubs,
            'announcements' => $announcements,
        ]);
    }

    public function students(Request $request)
    {
        $query = VisibleRecords::students($request->user())->with('user');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('student_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('course', 'like', "%{$search}%");
            });
        }

        if ($request->filled('course')) {
            $query->where('course', $request->input('course'));
        }

        $students = $query->orderBy('id', 'desc')->paginate(15)->withQueryString();

        return Inertia::render('Students/Index', [
            'students' => $students,
            'courses' => VisibleRecords::students($request->user())->distinct()->orderBy('course')->pluck('course'),
            'filters' => $request->only(['search', 'course']),
        ]);
    }

    public function clubs(Request $request)
    {
        $clubs = Club::with('adviser')->withCount(['memberships' => fn ($q) => $q->where('status', 'Active')])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->search.'%')->orWhere('code', 'like', '%'.$request->search.'%')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->orderBy('id', 'desc')
            ->paginate(15)->withQueryString();

        return Inertia::render('Clubs/Index', [
            'clubs' => $clubs,
            'filters' => $request->only(['search', 'category']),
            'categories' => Club::distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function achievements(Request $request)
    {
        $achievements = VisibleRecords::achievements($request->user())->with('submitter.student')->orderBy('id', 'desc')
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->paginate(15)->withQueryString();

        return Inertia::render('Achievements/Index', [
            'achievements' => $achievements,
            'filters' => $request->only(['search', 'status']),
            'statuses' => VisibleRecords::achievements($request->user())->distinct()->orderBy('status')->pluck('status'),
        ]);
    }
}
