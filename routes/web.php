<?php

use App\Http\Controllers\CollectionNoticeChunkUploadController;
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

    Route::post('/recaudo/comunicados/uploads/chunk', [CollectionNoticeChunkUploadController::class, 'store'])
        ->name('recaudo.comunicados.uploads.chunk');

    Route::delete('/recaudo/comunicados/files/{file}', App\Http\Controllers\Recaudo\Comunicados\DeleteCollectionNoticeRunFileController::class)
        ->name('recaudo.comunicados.files.destroy');

    Route::post('/recaudo/comunicados/files/{file}/replace', App\Http\Controllers\Recaudo\Comunicados\ReplaceFileController::class)
        ->name('recaudo.comunicados.files.replace');

    Route::post('/recaudo/comunicados/{run}/revalidate', App\Http\Controllers\Recaudo\Comunicados\RevalidateRunController::class)
        ->name('recaudo.comunicados.revalidate');

    Route::get('/recaudo/comunicados/{run}/results/{resultFile}', App\Http\Controllers\Recaudo\Comunicados\DownloadResultFileController::class)
        ->name('recaudo.comunicados.download-result');
});
