<?php

namespace App\Http\Middleware;

use App\Models\Club;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $assignedClub = null;

        if ($user && $user->role === 'club_adviser') {
            $assignedClub = Club::where('adviser_user_id', $user->id)
                ->select('id', 'name', 'code', 'program')
                ->first();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id'            => $user->id,
                    'username'      => $user->username,
                    'first_name'    => $user->first_name,
                    'last_name'     => $user->last_name,
                    'email'         => $user->email,
                    'role'          => $user->role,
                    'status'        => $user->status,
                    'profile_pic'   => $user->profile_pic,
                    'assigned_club' => $assignedClub,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
                'info'    => fn () => $request->session()->get('info'),
            ],
            'upcomingAlert' => fn () => $user ? \App\Models\Event::where('status', 'Approved')
                ->where('event_date', '>=', now()->subHours(4))
                ->orderBy('event_date', 'asc')
                ->with('club')
                ->first() : null,
        ];
    }
}
