<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ใช้ Bearer token mode ไม่ใช่ SPA stateful → ไม่ต้องเปิด statefulApi()
        // $middleware->statefulApi();
        $middleware->alias([
            'permission'            => \App\Http\Middleware\CheckPermission::class,
            'role'                  => \App\Http\Middleware\CheckRole::class,
            'hiptime.token'         => \App\Http\Middleware\VerifyHipTimeToken::class,
            'labour_importer.token' => \App\Http\Middleware\VerifyLabourImporterToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
