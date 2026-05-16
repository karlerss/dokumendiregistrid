<?php

use App\Http\Controllers\Api\ApiDocsController;
use App\Http\Controllers\Api\DocumentApiController;
use Illuminate\Support\Facades\Route;

Route::get('/docs', [ApiDocsController::class, 'ui'])->name('api.docs');
Route::get('/openapi.json', [ApiDocsController::class, 'spec'])->name('api.openapi');

Route::get('/documents', [DocumentApiController::class, 'index'])->name('api.documents.index');
Route::get('/documents/{document}', [DocumentApiController::class, 'show'])->name('api.documents.show');
Route::get('/organisations', [DocumentApiController::class, 'organisations'])->name('api.organisations.index');
