<?php

use App\Http\Controllers\Api\SupportTicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SupportIA API Routes
|--------------------------------------------------------------------------
|
| À inclure dans routes/api.php :
|   require __DIR__ . '/supportia.php';
|
*/

Route::middleware(['auth:sanctum'])->prefix('tickets')->group(function () {
    Route::get('/', [SupportTicketController::class, 'index']);
    Route::post('/', [SupportTicketController::class, 'store']);
    Route::post('/{ticket}/confirm', [SupportTicketController::class, 'confirm']);
});
