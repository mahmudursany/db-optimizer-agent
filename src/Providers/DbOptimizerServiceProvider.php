<?php

namespace Mdj\DbOptimizer\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Mdj\DbOptimizer\Http\Middleware\EnsureDbOptimizerAccess;
use Mdj\DbOptimizer\Http\Middleware\EnsureDbOptimizerAgentToken;
use Mdj\DbOptimizer\Services\QueryInterceptor;

class DbOptimizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/db_optimizer.php', 'db_optimizer');
    }

    public function boot(QueryInterceptor $interceptor): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('db-optimizer.access', EnsureDbOptimizerAccess::class);
        $router->aliasMiddleware('db-optimizer.agent', EnsureDbOptimizerAgentToken::class);

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'db-optimizer');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/db_optimizer.php' => config_path('db_optimizer.php'),
            ], 'db-optimizer-config');
        }

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
