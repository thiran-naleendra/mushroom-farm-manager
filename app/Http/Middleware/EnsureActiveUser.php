<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $request->user()->refresh();
        if (! $request->user()->canAccessApp()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your account or role is inactive. Contact a farm owner.']);
        }

        return $next($request);
    }
}
