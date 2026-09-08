<?php

use App\Http\Controllers\MainController;
use App\Http\Controllers\RecheckController;
use App\Http\Controllers\SitemapsController;
use App\Http\Controllers\TakedownController;
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

Route::post('/dokumendid/{document}/eemaldamistaotlus', [TakedownController::class, 'store'])->name('takedowns.store');

Route::get('/eemaldamistaotlus/{takedownRequest}', [TakedownController::class, 'track'])->name('takedowns.track');
Route::post('/eemaldamistaotlus/{takedownRequest}/kinnita', [TakedownController::class, 'verify'])->name('takedowns.verify');
Route::post('/eemaldamistaotlus/{takedownRequest}/saada-uuesti', [TakedownController::class, 'resend'])->name('takedowns.resend');

Route::get('/haldus/eemaldamistaotlused', [TakedownController::class, 'index'])->name('takedowns.index');
Route::get('/haldus/eemaldamistaotlused/{takedownRequest}', [TakedownController::class, 'show'])->name('takedowns.show');
Route::post('/haldus/eemaldamistaotlused/{takedownRequest}/rahulda', [TakedownController::class, 'accept'])->name('takedowns.accept');
Route::post('/haldus/eemaldamistaotlused/{takedownRequest}/keeldu', [TakedownController::class, 'deny'])->name('takedowns.deny');

Route::get('/haldus/kontroll', [RecheckController::class, 'index'])->name('recheck.index');
Route::post('/haldus/kontroll/{change}/kinnita', [RecheckController::class, 'acknowledge'])->name('recheck.acknowledge');
Route::post('/haldus/kontroll/{change}/peida', [RecheckController::class, 'hide'])->name('recheck.hide');
Route::post('/haldus/kontroll/{change}/naita', [RecheckController::class, 'unhide'])->name('recheck.unhide');
Route::post('/haldus/kontroll/{change}/kustuta-failid', [RecheckController::class, 'deleteFiles'])->name('recheck.deleteFiles');
Route::post('/haldus/kontroll/{change}/lae-uuesti', [RecheckController::class, 'refetch'])->name('recheck.refetch');
Route::post('/haldus/kontroll/{change}/ignoreeri', [RecheckController::class, 'ignore'])->name('recheck.ignore');
Route::post('/haldus/kontroll/kinnita-koik', [RecheckController::class, 'acknowledgeAll'])->name('recheck.acknowledgeAll');

Route::get('login', [MainController::class, 'login'])->name('login');
Route::post('login', [MainController::class, 'login']);
Route::get('logout', [MainController::class, 'logout'])->name('logout');

Route::get('sitemap.xml', [SitemapsController::class, 'index'])->name('sitemap');
Route::get('sitemap-pages.xml', [SitemapsController::class, 'pages'])->name('sitemap');
Route::get('sitemaps/{orgSlug}/{year}.xml', [SitemapsController::class, 'orgSitemap'])->name('sitemap.org');
