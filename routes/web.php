<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use ProjectSendSftp\DestinationController;
use ProjectSendSftp\TransferSelectionController;
use ProjectSendSftp\TransferController;

Route::middleware(['web', 'auth', 'staff', 'can:sftp.transfer'])
    ->prefix('sftp')->name('sftp.')->group(function (): void {
        Route::get('/files', [TransferSelectionController::class, 'files'])->name('selection.files');
        Route::get('/destinations', [TransferSelectionController::class, 'destinations'])->name('selection.destinations');
        Route::redirect('/', '/sftp/transfers');
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
        Route::get('/transfers/review/{file}', [TransferController::class, 'review'])->whereNumber('file')->name('transfers.review');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->whereUuid('transfer')->name('transfers.show');
        Route::get('/transfers/{transfer}/status', [TransferController::class, 'status'])->whereUuid('transfer')->name('transfers.status');
    });

Route::middleware(['web', 'auth', 'staff', 'can:sftp.manage-destinations'])
    ->prefix('system/settings/sftp')->name('sftp.destinations.')->group(function (): void {
        Route::get('/', [DestinationController::class, 'index'])->name('index');
        Route::get('/create', [DestinationController::class, 'create'])->name('create');
        Route::post('/', [DestinationController::class, 'store'])->name('store');
        Route::get('/{destination}/edit', [DestinationController::class, 'edit'])->name('edit');
        Route::get('/{destination}/delete', [DestinationController::class, 'confirmDeletion'])->name('delete');
        Route::patch('/{destination}', [DestinationController::class, 'update'])->name('update');
        Route::delete('/{destination}', [DestinationController::class, 'destroy'])->name('destroy');
    });
