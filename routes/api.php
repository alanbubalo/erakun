<?php

declare(strict_types=1);

use App\Http\Controllers\InboundInvoiceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceStatusController;
use App\Http\Controllers\InvoiceXmlController;
use App\Http\Controllers\TaxpayerController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'message' => 'Welcome to eRakun API',
]));

Route::post('/taxpayers', [TaxpayerController::class, 'store']);
Route::get('/taxpayers/{taxpayer}', [TaxpayerController::class, 'show']);

Route::post('/invoices', [InvoiceController::class, 'store']);
Route::post('/invoices/inbound', [InboundInvoiceController::class, 'store']);
Route::get('/invoices', [InvoiceController::class, 'index']);
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
Route::get('/invoices/{invoice}/xml', [InvoiceXmlController::class, 'show']);
Route::patch('/invoices/{invoice}/status', [InvoiceStatusController::class, 'update']);
