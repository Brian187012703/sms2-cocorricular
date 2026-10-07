<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function users(Request $request)
    {
        $user = Auth::user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access to central user administration.');
        }

        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('username', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%");
            });
        }

        $users = $query->orderBy('id', 'desc')->paginate(20)->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $request->only(['role', 'status', 'search']),
            'metrics' => [
                'total_users' => User::count(),
                'students' => User::where('role', 'student')->count(),
                'advisers' => User::where('role', 'club_adviser')->count(),
                'ssc' => User::where('role', 'ssc')->count(),
                'admins' => User::where('role', 'admin')->count(),
            ],
        ]);
    }

    public function toggleStatus(Request $request, User $targetUser)
    {
        $admin = Auth::user();
        if ($admin->role !== 'admin') {
            abort(403, 'Unauthorized.');
        }

        if ($targetUser->id === $admin->id) {
            return back()->with('error', 'You cannot deactivate your own administrative account.');
        }

        $newStatus = ($targetUser->status === 'Active') ? 'Inactive' : 'Active';
        $targetUser->update(['status' => $newStatus]);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'USER_STATUS_TOGGLED',
            'details' => "Changed {$targetUser->username} status to {$newStatus}",
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', "User {$targetUser->username} is now {$newStatus}.");
    }

    public function storeUser(Request $request)
    {
        $admin = Auth::user();
        if ($admin->role !== 'admin') {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|email|max:100|unique:users,email',
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:50',
            'role' => 'required|in:student,club_adviser,ssc,admin',
            'password' => 'required|string|min:6',
        ]);

        $newUser = User::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'role' => $validated['role'],
            'status' => 'Active',
            'password_hash' => password_hash($validated['password'], PASSWORD_DEFAULT),
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'USER_CREATED',
            'details' => "Created user {$newUser->username} with role {$newUser->role}",
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', "User {$newUser->username} provisioned successfully.");
    }

    public function audit(Request $request)
    {
        $user = Auth::user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access to security audit trail.');
        }

        $query = AuditLog::with('user');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('action', 'like', "%{$s}%")
                    ->orWhere('details', 'like', "%{$s}%")
                    ->orWhere('ip_address', 'like', "%{$s}%");
            });
        }

        $logs = $query->orderBy('id', 'desc')->paginate(25)->withQueryString();

        return Inertia::render('Admin/Audit', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'search']),
        ]);
    }
}
