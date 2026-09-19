<?php

namespace App\Http\Routes\V2;

use Illuminate\Contracts\Routing\Registrar;

class ModelRelayRoute
{
    public function map(Registrar $router)
    {
        $router->post('model-relay/internal/{operation}', [\App\Http\Controllers\V2\ModelRelayInternalController::class, 'handle'])
            ->where('operation', 'models|reserve|settle|upstream|connect');
    }
}
