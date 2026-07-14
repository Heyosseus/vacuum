<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Http\Controllers\ConsoleController;
use Illuminate\Support\Facades\Route;

Route::get('/console', [ConsoleController::class, 'show'])->name('vacuum.console');
Route::post('/console', [ConsoleController::class, 'run'])->name('vacuum.console.run');
