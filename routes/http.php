<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravents\Http\CommandController;
use Laravents\Http\QueryController;

// Loaded by LaraventsServiceProvider inside the laravents.http prefix and middleware, when laravents.http.enabled.
// Named before they are added, so the names resolve however late the routes are registered.

/** @return list<string> */
$middleware = static function (string $key): array {
    $middleware = config('laravents.http.'.$key, []);

    return is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : [];
};

Route::name('laravents.commands')->post('commands/{key}', CommandController::class)->middleware($middleware('command_middleware'));
Route::name('laravents.queries')->match(['GET', 'POST'], 'queries/{key}', QueryController::class)->middleware($middleware('query_middleware'));
