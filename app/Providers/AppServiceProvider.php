<?php

namespace App\Providers;

use App\Lib\DB\SQLiteGrammar;
use App\Lib\LLM\AiProvider;
use App\Lib\LLM\OpenAI;
use App\Lib\Pii\PdfRedactor;
use App\Lib\Pii\PyMuPdfRedactor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Model provider for the PII extraction pipeline; tests bind a fake.
        $this->app->bind(AiProvider::class, fn() => new OpenAI(config('pii.model')));
        $this->app->bind(PdfRedactor::class, PyMuPdfRedactor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app('db.connection')->setQueryGrammar(new SQLiteGrammar());

        RateLimiter::for('api', function (Request $request) {
            return (new Limit($request->ip(), 10, 10))->response(function () {
                return response()->json([
                    'error' => 'Too Many Requests',
                    'message' => 'Rate limit exceeded. The API allows at most 10 requests per 10 seconds per IP.',
                ], 429);
            });
        });
    }
}
