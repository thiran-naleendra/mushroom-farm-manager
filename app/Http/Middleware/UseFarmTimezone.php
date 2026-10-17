<?php

namespace App\Http\Middleware;

use App\Models\Farm;
use Closure;
use Illuminate\Http\Request;

class UseFarmTimezone
{
    public function handle(Request $request, Closure $next)
    {
        $original = date_default_timezone_get();
        $config = config('app.timezone');
        $timezone = Farm::value('timezone') ?? $config;
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
        try {
            return $next($request);
        } finally {
            config(['app.timezone' => $config]);
            date_default_timezone_set($original);
        }
    }
}
