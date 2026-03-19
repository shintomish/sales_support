<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\LogUserActivity;
use App\Http\Middleware\SupabaseAuth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'supabase.auth' => SupabaseAuth::class,
        ]);
        $middleware->appendToGroup('api', SetTenantContext::class);
        $middleware->appendToGroup('api', LogUserActivity::class);
        $middleware->appendToGroup('web', LogUserActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
