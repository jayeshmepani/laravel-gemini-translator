<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Jayesh\LaravelGeminiTranslator\Http\Controllers\ManagerController;
use Jayesh\LaravelGeminiTranslator\Http\Middleware\EnsureManagerAuthenticated;

$prefix = (string) config('gemini-translator.manager.prefix', 'translations-manager');
$middleware = config('gemini-translator.manager.middleware', ['web']);
$middleware = is_array($middleware) ? $middleware : ['web'];
$middleware[] = EnsureManagerAuthenticated::class;

Route::middleware($middleware)
    ->prefix($prefix)
    ->name('gemini-translator.manager.')
    ->group(static function (): void {
        Route::get('/', [ManagerController::class, 'show'])->name('show');
        Route::get('/data', [ManagerController::class, 'data'])->name('data');
        Route::get('/languages', [ManagerController::class, 'languages'])->name('languages');
        Route::get('/existing', [ManagerController::class, 'existing'])->name('existing');
        Route::post('/scan', [ManagerController::class, 'scan'])->name('scan');
        Route::post('/save', [ManagerController::class, 'save'])->name('save');
        Route::post('/add-languages', [ManagerController::class, 'addLanguages'])->name('add-languages');
    });
