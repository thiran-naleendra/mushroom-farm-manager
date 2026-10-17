<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\UseFarmTimezone;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureActiveUser::class, 'farm.timezone' => UseFarmTimezone::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
