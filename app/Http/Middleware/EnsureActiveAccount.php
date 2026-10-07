<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->status === 'Active' && in_array($request->user()?->role, ['student', 'club_adviser', 'ssc', 'admin'], true), 403, 'Account access is disabled.');

        return $next($request);
    }
}
