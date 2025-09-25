<?php

use App\Http\Controllers\CollectionNoticeRunsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/recaudo/comunicados', [CollectionNoticeRunsController::class, 'index'])
        ->name('recaudo.comunicados.index');

    Route::delete('/recaudo/comunicados/{run}', [CollectionNoticeRunsController::class, 'destroy'])
        ->name('recaudo.comunicados.destroy');
});
