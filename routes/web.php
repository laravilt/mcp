<?php

use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

Route::post('/webhooks/github', GitHubWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.github');
