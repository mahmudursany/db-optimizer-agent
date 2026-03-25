<?php

use App\Http\Controllers\DbOptimizerAgentController;
use App\Http\Controllers\DbOptimizerDashboardController;
use App\Http\Controllers\DbOptimizerScannerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('_db-optimizer')
    ->name('db-optimizer.')
    ->middleware('db-optimizer.access')
    ->group(function (): void {
        Route::get('/', [DbOptimizerDashboardController::class, 'index'])->name('index');
        Route::get('/snapshots/{snapshotId}', [DbOptimizerDashboardController::class, 'show'])->name('show');
        Route::get('/scanner', [DbOptimizerScannerController::class, 'index'])->name('scanner.index');
        Route::post('/scanner/run', [DbOptimizerScannerController::class, 'run'])->name('scanner.run');
    });

Route::prefix('_db-optimizer/agent')
    ->middleware('db-optimizer.agent')
    ->group(function (): void {
        Route::get('/ping', [DbOptimizerAgentController::class, 'ping']);
        Route::get('/snapshots', [DbOptimizerAgentController::class, 'snapshots']);
        Route::post('/reset', [DbOptimizerAgentController::class, 'reset']);
    });
