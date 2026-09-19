<?php

namespace App\Providers;

use App\Services\Plugin\PluginManager;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PluginManager::class, function () {
            return new PluginManager();
        });

        spl_autoload_register(function (string $class): void {
            if (strpos($class, 'Plugin\\') !== 0) {
                return;
            }

            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 7)) . '.php';
            foreach (['plugins-core', 'plugins'] as $directory) {
                $file = base_path($directory . DIRECTORY_SEPARATOR . $relativePath);
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        }, true, true);
    }

    public function boot(): void
    {
        foreach (['plugins', 'plugins-core'] as $directory) {
            $path = base_path($directory);
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}
