<?php

putenv('APP_KEY=base64:0FYFqVlS7NVBC2ygcwyp7ExfWgZGHYrFHE7BcM1oNy8=');
$_ENV['APP_KEY'] = 'base64:0FYFqVlS7NVBC2ygcwyp7ExfWgZGHYrFHE7BcM1oNy8=';
$_SERVER['APP_KEY'] = 'base64:0FYFqVlS7NVBC2ygcwyp7ExfWgZGHYrFHE7BcM1oNy8=';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
