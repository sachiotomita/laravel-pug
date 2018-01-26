<?php

namespace Bkwld\LaravelPug;

// Dependencies
use Illuminate\View\Engines\CompilerEngine;
use Pug\Assets;
use Pug\Pug;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    protected function setDefaultOption(Pug $pug, $name, $value)
    {
        if (method_exists($pug, 'hasOption') && !$pug->hasOption($name)) {
            $pug->setCustomOption($name, call_user_func($value));

            return;
        }

        // @codeCoverageIgnoreStart
        try {
            $pug->getOption($name);
        } catch (\InvalidArgumentException $exception) {
            $pug->setCustomOption($name, call_user_func($value));
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get the major Laravel version number.
     *
     * @return int
     */
    public function version()
    {
        $app = $this->app;
        $tab = explode('Laravel Components ', $app->version());

        return intval(empty($tab[1]) ? $app::VERSION : $tab[1]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Version specific registering
        if ($this->version() >= 5) {
            $this->registerLaravel5();
        }

        $assets = null;

        $this->app->singleton('laravel-pug.pug-assets', function () use (&$assets) {
            return $this->app['laravel-pug.pug'] ? $assets : null;
        });

        $getDefaultCache = function () {
            return storage_path($this->version() >= 5 ? '/framework/views' : '/views');
        };

        // Bind the package-configued Pug instance
        $this->app->singleton('laravel-pug.pug', function () use (&$assets, $getDefaultCache) {
            $config = $this->getConfig();
            $pug = new Pug($config);
            $assets = new Assets($pug);
            $getEnv = array('App', 'environment');
            $assets->setEnvironment(is_callable($getEnv) ? call_user_func($getEnv) : 'production');

            // Determine the cache dir if not configured
            $this->setDefaultOption($pug, 'defaultCache', $getDefaultCache);

            // Determine assets input directory
            $this->setDefaultOption($pug, 'assetDirectory', function () {
                return array_map(function ($params) {
                    list($function, $arg) = $params;

                    return function_exists($function) ? call_user_func($function, $arg) : null;
                }, array(
                    array('resource_path', 'assets'),
                    array('app_path', 'views/assets'),
                    array('app_path', 'assets'),
                    array('app_path', 'views'),
                    array('app_path', ''),
                    array('base_path', ''),
                ));
            });

            // Determine assets output directory
            $this->setDefaultOption($pug, 'outputDirectory', function () {
                return function_exists('public_path') ? public_path() : null;
            });

            return $pug;
        });

        $createCompiler = function ($compilerClass) use ($getDefaultCache) {
            return function ($app) use ($compilerClass, $getDefaultCache) {
                return new $compilerClass(array($app, 'laravel-pug.pug'), $app['files'], $this->getConfig(), $getDefaultCache());
            };
        };

        // Bind the Pug compiler
        $this->app->singleton('Bkwld\LaravelPug\PugCompiler', $createCompiler('\Bkwld\LaravelPug\PugCompiler'));

        // Bind the Pug Blade compiler
        $this->app->singleton('Bkwld\LaravelPug\PugBladeCompiler', $createCompiler('\Bkwld\LaravelPug\PugBladeCompiler'));
    }

    /**
     * Register specific logic for Laravel 5. Merges package config with user config.
     *
     * @return void
     */
    public function registerLaravel5()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'laravel-pug');
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        // Version specific booting
        switch ($this->version()) {
            case 4: $this->bootLaravel4(); break;
            case 5: $this->bootLaravel5(); break;
            default: throw new Exception('Unsupported Laravel version.');
        }

        // Register compilers
        $this->registerPugCompiler();
        $this->registerPugBladeCompiler();
    }

    /**
     * Boot specific logic for Laravel 4. Tells Laravel about the package for auto
     * namespacing of config files.
     *
     * @return void
     */
    public function bootLaravel4()
    {
        $this->package('bkwld/laravel-pug');
    }

    /**
     * Boot specific logic for Laravel 5. Registers the config file for publishing
     * to app directory.
     *
     * @return void
     */
    public function bootLaravel5()
    {
        if (function_exists('config_path')) {
            $this->publishes(array(
                __DIR__ . '/../config/config.php' => config_path('laravel-pug.php'),
            ), 'laravel-pug');
        }
    }

    /**
     * Returns the view engine resolver according to current framework (laravel/lumen).
     *
     * @return \Illuminate\View\Engines\EngineResolver
     */
    public function getEngineResolver()
    {
        return isset($this->app['view.engine.resolver'])
            ? $this->app['view.engine.resolver']
            : $this->app['view']->getEngineResolver();
    }

    /**
     * Register the regular Pug compiler.
     *
     * @return void
     */
    public function registerPugCompiler()
    {
        // Add resolver
        $this->getEngineResolver()->register('pug', function () {
            return new CompilerEngine($this->app['Bkwld\LaravelPug\PugCompiler']);
        });

        // Add extensions
        $this->app['view']->addExtension('pug', 'pug');
        $this->app['view']->addExtension('pug.php', 'pug');
        $this->app['view']->addExtension('jade', 'pug');
        $this->app['view']->addExtension('jade.php', 'pug');
    }

    /**
     * Register the blade compiler compiler.
     *
     * @return void
     */
    public function registerPugBladeCompiler()
    {
        // Add resolver
        $this->getEngineResolver()->register('pug.blade', function () {
            return new CompilerEngine($this->app['Bkwld\LaravelPug\PugBladeCompiler']);
        });

        // Add extensions
        $this->app['view']->addExtension('pug.blade', 'pug.blade');
        $this->app['view']->addExtension('pug.blade.php', 'pug.blade');
        $this->app['view']->addExtension('jade.blade', 'jade.blade');
        $this->app['view']->addExtension('jade.blade.php', 'jade.blade');
    }

    /**
     * Get the configuration, which is keyed differently in L5 vs l4.
     *
     * @return array
     */
    public function getConfig()
    {
        $key = $this->version() >= 5 ? 'laravel-pug' : 'laravel-pug::config';

        return $this->app->make('config')->get($key);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array(
            'Bkwld\LaravelPug\PugCompiler',
            'Bkwld\LaravelPug\PugBladeCompiler',
            'laravel-pug.pug',
        );
    }
}
