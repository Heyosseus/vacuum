<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('vacuum.dashboard');
