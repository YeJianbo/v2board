<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\AgentOperationController;
use Illuminate\Contracts\Routing\Registrar;

class AgentOperationRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            $router->get('agent/operations', [AgentOperationController::class, 'history']);
            $router->get('agent/operation', [AgentOperationController::class, 'detail']);
            $router->post('agent/undo-preview', [AgentOperationController::class, 'undoPreview']);
            $router->post('agent/undo', [AgentOperationController::class, 'undo']);
            $router->post('agent/cancel', [AgentOperationController::class, 'cancel']);
            $router->post('agent/confirm-batch', [\App\Http\Controllers\V2\Admin\AgentController::class, 'confirmMany']);
        });
    }
}
