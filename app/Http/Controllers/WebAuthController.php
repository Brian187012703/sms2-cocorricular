<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class WebAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $inputName = trim($credentials['username']);
        $user = User::where('username', $inputName)
            ->orWhere('email', $inputName)
            ->first();

        if (! $user) {
            return back()->withErrors([
                'username' => 'The provided credentials do not match our records.',
            ]);
        }

        if ($user->status !== 'Active') {
            return back()->withErrors([
                'username' => 'This account has been deactivated. Please contact an administrator.',
            ]);
        }

        $isValidPassword = password_verify($credentials['password'], $user->password_hash);
        if (! $isValidPassword) {
            return back()->withErrors([
                'password' => 'The provided password is incorrect.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->update(['last_login' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
