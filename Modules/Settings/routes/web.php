<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\app\Http\Controllers\V1\SettingController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('settings', SettingController::class)->names('settings');
});