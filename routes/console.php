<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Http\Controllers\ConsoleController;
use Illuminate\Support\Facades\Route;

Route::get('/console', [ConsoleController::class, 'show'])->name('vacuum.console');

$limit = config('vacuum.console.rate_limit', '30,1');

Route::post('/console', [ConsoleController::class, 'run'])
    ->middleware(is_string($limit) && $limit !== '' ? ["throttle:{$limit}"] : [])
    ->name('vacuum.console.run');
