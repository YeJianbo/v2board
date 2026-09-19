<?php

namespace App\Http\Middleware;

use App\Services\Plugin\PluginManager;
use Closure;
use Illuminate\Http\Request;

class InitializePlugins
{
    protected PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    public function handle(Request $request, Closure $next)
    {
        $this->pluginManager->initializeEnabledPlugins();

        return $next($request);
    }
}
