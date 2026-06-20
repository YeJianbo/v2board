<?php

namespace App\Http\Routes\V2;

use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server'
        ], function ($router) {
            $router->any('/{action}', function ($action) {
                $ctrl = \App::make(\App\Http\Controllers\V2\Server\ServerController::class);
                return \App::call([$ctrl, $action]);
            });
        });
    }
}
