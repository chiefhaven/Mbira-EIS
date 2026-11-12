<?php

use Illuminate\Support\Facades\Route;
use Modules\EIS\Http\Controllers\StockOutboundController;
use Modules\EIS\Http\Controllers\TerminalManagementController;
use Modules\EIS\Http\Controllers\UtilitiesController;
use Modules\EIS\Http\Controllers\SalesController;
use Modules\EIS\Http\Controllers\ProductStatusController;
use Modules\EIS\Http\Controllers\PosBuyerController;

Route::prefix('eis')->middleware('auth')->group(function () {
    Route::get('install', [\Modules\EIS\Http\Controllers\InstallController::class, 'index'])
        ->withoutMiddleware(\Modules\EIS\Http\Middleware\EnforceTerminalBusiness::class);
    Route::get('install/update', [\Modules\EIS\Http\Controllers\InstallController::class, 'update'])
        ->withoutMiddleware(\Modules\EIS\Http\Middleware\EnforceTerminalBusiness::class);
    Route::get('install/uninstall', [\Modules\EIS\Http\Controllers\InstallController::class, 'uninstall'])
        ->withoutMiddleware(\Modules\EIS\Http\Middleware\EnforceTerminalBusiness::class);
});

Route::prefix('eis/sales')->middleware('auth')->name('eis.sales.')->group(function () {
    Route::get('/', [SalesController::class, 'index'])->name('index');
    Route::post('lookup', [SalesController::class, 'lookup'])->name('lookup');
    Route::post('{id}/sync', [SalesController::class, 'syncSale'])->whereNumber('id')->name('sync');
    Route::get('adjustments/{id}', [SalesController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('adjustments/{id}/submit', [SalesController::class, 'submit'])->whereNumber('id')->name('submit');
});

Route::post('eis/products/{productId}/status', [ProductStatusController::class, 'show'])
    ->whereNumber('productId')->middleware('auth')->name('eis.products.status');

Route::post('eis/pos/buyer-authorization-requirement', [PosBuyerController::class, 'authorizationRequirement'])
    ->middleware('auth')->name('eis.pos.buyer-authorization-requirement');

Route::get('eis/terminal-block-status', [TerminalManagementController::class, 'blockingStatus'])
    ->middleware('auth')->withoutMiddleware(\Modules\EIS\Http\Middleware\RequireEisSubscription::class)
    ->name('eis.terminal-block-status');

Route::prefix('eis/sales/operations')->middleware('auth')->name('eis.sales.operations.')->group(function () {
    Route::post('execute', [UtilitiesController::class, 'execute'])->name('execute');
    Route::get('requests/{requestId}', [UtilitiesController::class, 'show'])->whereNumber('requestId')->name('show');
    Route::post('requests/{requestId}/submit', [UtilitiesController::class, 'submit'])->whereNumber('requestId')->name('submit');
});

Route::get('eis', function () {
    $user = auth()->user();

    if ($user->can('business_settings.access')) {
        return redirect()->route('eis.terminals.page');
    }

    if ($user->can('stock_adjustment.view')) {
        return redirect()->route('eis.stock.index');
    }

    abort(403);
})->middleware('auth')->name('eis.index');

Route::prefix('eis/stock')->middleware('auth')->name('eis.stock.')->group(function () {
    Route::get('/', [\Modules\EIS\Http\Controllers\StockReconciliationController::class, 'index'])->name('index');
    Route::post('preview', [\Modules\EIS\Http\Controllers\StockReconciliationController::class, 'preview'])->name('preview');
    Route::post('{snapshotId}/apply', [\Modules\EIS\Http\Controllers\StockReconciliationController::class, 'apply'])
        ->whereNumber('snapshotId')->name('apply');
});

// Session authentication and the web middleware's CSRF protection are required.
Route::prefix('eis/terminals')->middleware(['web', 'auth'])->name('eis.terminals.')->group(function () {
    Route::get('/', [TerminalManagementController::class, 'page'])->name('page');
    Route::get('data', [TerminalManagementController::class, 'index'])->name('index');
    Route::post('activate', [TerminalManagementController::class, 'activate'])->name('activate');
    Route::post('{terminalId}/latest-config', [TerminalManagementController::class, 'latestConfig'])
        ->whereNumber('terminalId')->name('latest-config');
    Route::get('{terminalId}/configuration', [TerminalManagementController::class, 'configuration'])
        ->whereNumber('terminalId')->name('configuration');
    Route::post('{terminalId}/request-new-token', [TerminalManagementController::class, 'requestNewToken'])
        ->whereNumber('terminalId')->name('request-new-token');
    Route::post('{terminalId}/deactivate', [TerminalManagementController::class, 'deactivate'])
        ->whereNumber('terminalId')->name('deactivate');
    Route::post('{terminalId}/renew-tokens', [TerminalManagementController::class, 'renewTokens'])
        ->whereNumber('terminalId')->name('renew-tokens');
    Route::post('{terminalId}/default', [TerminalManagementController::class, 'setDefault'])
        ->whereNumber('terminalId')->name('default');
});

Route::get('eis/stock/outbound/locations/{locationId}/adjustment-reasons', [StockOutboundController::class, 'reasons'])
    ->whereNumber('locationId')->middleware(['web', 'auth'])->name('eis.stock.outbound.reasons');
