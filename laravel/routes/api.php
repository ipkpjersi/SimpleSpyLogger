<?php

use App\Http\Controllers\Api\MessageIngestController;
use Illuminate\Support\Facades\Route;

Route::middleware('ingest.token')->group(function () {
    Route::post('/messages/ingest', [MessageIngestController::class, 'store']);
});
