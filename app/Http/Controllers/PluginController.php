<?php

namespace App\Http\Controllers;

use App\Traits\HasPluginConfig;

abstract class PluginController extends Controller
{
    use HasPluginConfig;

    protected function beforePluginAction(): ?array
    {
        if (!$this->isPluginEnabled()) {
            return [400, '插件未启用'];
        }

        return null;
    }
}
