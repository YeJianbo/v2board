<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    private const ALLOWED_ENDPOINTS = [
        'deepbwork' => [
            'controller' => 'DeepbworkController',
            'actions' => [
                'user' => 'user',
                'submit' => 'submit',
                'config' => 'config',
            ],
        ],
        'machine' => [
            'controller' => 'MachineController',
            'actions' => [
                'config' => 'config',
                'push' => 'push',
                'enroll' => 'enroll',
                'v2nodeconfig' => 'v2nodeConfig',
                'restartack' => 'restartAck',
                'bbrack' => 'bbrAck',
                'connectivitytestack' => 'connectivityTestAck',
            ],
        ],
        'machineapi' => [
            'controller' => 'MachineApiController',
            'actions' => [
                'pushstatus' => 'pushStatus',
                'fetchcommand' => 'fetchCommand',
            ],
        ],
        'shadowsockstidalab' => [
            'controller' => 'ShadowsocksTidalabController',
            'actions' => [
                'user' => 'user',
                'submit' => 'submit',
            ],
        ],
        'trojantidalab' => [
            'controller' => 'TrojanTidalabController',
            'actions' => [
                'user' => 'user',
                'submit' => 'submit',
                'config' => 'config',
            ],
        ],
        'uniproxy' => [
            'controller' => 'UniProxyController',
            'actions' => [
                'user' => 'user',
                'push' => 'push',
                'alivelist' => 'alivelist',
                'alive' => 'alive',
                'config' => 'config',
            ],
        ],
    ];

    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server'
        ], function ($router) {
            $router->any('/{class}/{action}', function($class, $action) {
                $classKey = strtolower((string) $class);
                $actionKey = strtolower((string) $action);
                $endpoint = self::ALLOWED_ENDPOINTS[$classKey] ?? null;

                if (!$endpoint || !isset($endpoint['actions'][$actionKey])) {
                    abort(404);
                }

                $controller = "\\App\\Http\\Controllers\\V1\\Server\\" . $endpoint['controller'];
                $method = $endpoint['actions'][$actionKey];
                $ctrl = \App::make($controller);
                return \App::call([$ctrl, $method]);
            });
        });
    }
}
