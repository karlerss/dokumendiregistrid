<?php

namespace App\Providers;

use App\Lib\DB\SQLiteGrammar;
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
        //
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
