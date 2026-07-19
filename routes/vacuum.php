<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Http\Controllers\DashboardController;
use Heyosseus\Vacuum\Http\Controllers\HistoryController;
use Heyosseus\Vacuum\Http\Controllers\InternalsController;
use Heyosseus\Vacuum\Http\Controllers\LearnController;
use Heyosseus\Vacuum\Http\Controllers\LessonController;
use Heyosseus\Vacuum\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('vacuum.dashboard');

Route::get('/tables/{schema}/{table}', TableController::class)->name('vacuum.table');

if (config('vacuum.history.enabled') === true) {
    Route::get('/history', HistoryController::class)->name('vacuum.history');
}

if (config('vacuum.internals.enabled') === true) {
    Route::get('/internals', InternalsController::class)->name('vacuum.internals');
}

if (config('vacuum.learn.enabled') === true) {
    Route::get('/learn', LearnController::class)->name('vacuum.learn');
    Route::get('/learn/{lesson}', LessonController::class)->name('vacuum.lesson');
}
