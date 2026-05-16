<?php

use App\Http\Controllers\MainController;
use App\Http\Controllers\SitemapsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MainController::class, 'index']);
Route::get('/dokumendid/{document}/{slug?}', [MainController::class, 'show'])->name('document');
Route::get('/toimikud/{slug}', [MainController::class, 'dossier'])->name('dossier');
Route::post('/documents/{document}/summarize', [MainController::class, 'summarize'])->name('summarize');

Route::get('/arhiiv', [MainController::class, 'archive'])->name('archive');
Route::get('/arhiiv/{orgSlug}', [MainController::class, 'archiveOrg'])->name('archiveOrg');
Route::get('/arhiiv/{orgSlug}/{year}', [MainController::class, 'archiveYear'])->name('archiveYear');
Route::get('/arhiiv/{orgSlug}/{year}/{month}', [MainController::class, 'archiveMonth'])->name('archiveMonth');

Route::get('/projektist', [MainController::class, 'about'])->name('about');

Route::delete('/dokumendid/{document}', [MainController::class, 'destroy'])->name('document.destroy');
Route::post('/dokumendid/{document}/reindex', [MainController::class, 'reindex'])->name('document.reindex');
Route::delete('/files/{file}', [MainController::class, 'deleteFile'])->name('file.destroy');
Route::post('/files/{file}/replace', [MainController::class, 'replaceFile'])->name('file.replace');

Route::get('login', [MainController::class, 'login'])->name('login');
Route::post('login', [MainController::class, 'login']);
Route::get('logout', [MainController::class, 'logout'])->name('logout');

Route::get('sitemap.xml', [SitemapsController::class, 'index'])->name('sitemap');
Route::get('sitemap-pages.xml', [SitemapsController::class, 'pages'])->name('sitemap');
Route::get('sitemaps/{orgSlug}/{year}.xml', [SitemapsController::class, 'orgSitemap'])->name('sitemap.org');
