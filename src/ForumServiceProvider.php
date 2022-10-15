<?php

namespace TeamTeaTime\Forum;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use TeamTeaTime\Forum\Console\Commands\Seed;
use TeamTeaTime\Forum\Console\Commands\SyncStats;
use TeamTeaTime\Forum\Http\Middleware\ResolveApiParameters;
use TeamTeaTime\Forum\Http\Middleware\ResolveWebParameters;

class ForumServiceProvider extends ServiceProvider
{
    public function boot(Router $router, GateContract $gate)
    {
        $this->publishes([
            __DIR__.'/../config/api.php' => config_path('forum.api.php'),
            __DIR__.'/../config/web.php' => config_path('forum.web.php'),
            __DIR__.'/../config/general.php' => config_path('forum.general.php'),
            __DIR__.'/../config/integration.php' => config_path('forum.integration.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'migrations');

        $this->publishes([
            __DIR__.'/../translations/' => resource_path('lang/vendor/forum'),
        ], 'translations');

        foreach (['api', 'web', 'general', 'integration'] as $name) {
            $this->mergeConfigFrom(__DIR__."/../config/{$name}.php", "forum.{$name}");
        }

        if (config('forum.api.enable')) {
            $this->enableApi($router);
        }

        if (config('forum.web.enable')) {
            $this->enableWeb($router);
        }

        $this->loadTranslationsFrom(__DIR__.'/../translations', 'forum');

        $this->registerPolicies($gate);

        // Make sure Carbon's locale is set to the application locale
        Carbon::setLocale(config('app.locale'));

        $loader = AliasLoader::getInstance();
        $loader->alias('Forum', config('forum.web.utility_class'));

        View::composer('forum::master', function ($view) {
            if (Auth::check()) {
                $nameAttribute = config('forum.integration.user_name');
                $view->username = Auth::user()->{$nameAttribute};
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Seed::class,
                SyncStats::class,
            ]);
        }
    }

    private function enableApi(Router $router)
    {
        $router->middlewareGroup('forum:api:resolve', [ResolveApiParameters::class]);

        $config = config('forum.api.router');
        $config['middleware'][] = 'forum:api:resolve';

        $router
            ->prefix($config['prefix'])
            ->name($config['as'])
            ->namespace($config['namespace'])
            ->middleware($config['middleware'])
            ->group(function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
            });
    }

    private function enableWeb(Router $router)
    {
        $router->middlewareGroup('forum:web:resolve', [ResolveWebParameters::class]);

        $this->publishes([
            __DIR__.'/../views/' => resource_path('views/vendor/forum'),
        ], 'views');

        $config = config('forum.web.router');
        $config['middleware'][] = 'forum:web:resolve';

        $router
            ->prefix($config['prefix'])
            ->name($config['as'])
            ->namespace($config['namespace'])
            ->middleware($config['middleware'])
            ->group(function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });

        $this->loadViewsFrom(__DIR__.'/../views', 'forum');
    }

    private function registerPolicies(GateContract $gate)
    {
        $forumPolicy = config('forum.integration.policies.forum');
        foreach (get_class_methods(new $forumPolicy()) as $method) {
            $gate->define($method, "{$forumPolicy}@{$method}");
        }

        foreach (config('forum.integration.policies.model') as $model => $policy) {
            $gate->policy($model, $policy);
        }
    }
}
