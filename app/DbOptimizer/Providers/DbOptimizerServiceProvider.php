<?php

namespace App\DbOptimizer\Providers;

use App\DbOptimizer\Services\QueryInterceptor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class DbOptimizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/db_optimizer.php'), 'db_optimizer');
    }

    public function boot(QueryInterceptor $interceptor): void
    {
        if (! (bool) config('db_optimizer.enabled', false)) {
            return;
        }

        if (app()->runningInConsole() && ! (bool) config('db_optimizer.capture_console', false)) {
            return;
        }

        DB::listen(static function (QueryExecuted $event) use ($interceptor): void {
            $interceptor->capture($event);
        });

        $this->app->terminating(static function () use ($interceptor): void {
            $interceptor->flushRequestSnapshot();
        });
    }
}
