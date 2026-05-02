<?php

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TaxpayerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to eRakun API',
    ]);
});

Route::post('/taxpayers', [TaxpayerController::class, 'store']);
Route::get('/taxpayers/{taxpayer}', [TaxpayerController::class, 'show']);

Route::post('/invoices', [InvoiceController::class, 'store']);
Route::get('/invoices', [InvoiceController::class, 'index']);
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
Route::get('/invoices/{invoice}/xml', [InvoiceController::class, 'xml']);
Route::patch('/invoices/{invoice}/status', [InvoiceController::class, 'updateStatus']);
