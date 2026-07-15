<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Http\Controllers\DashboardController;
use Heyosseus\Vacuum\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('vacuum.dashboard');

Route::get('/tables/{schema}/{table}', TableController::class)->name('vacuum.table');
