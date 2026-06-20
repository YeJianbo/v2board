<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;

class MachineApiController extends Controller
{
    // High security: strictly check token
    protected function authenticate(Request $request)
    {
        abort(404);
    }

    public function pushStatus(Request $request)
    {
        $machine = $this->authenticate($request);
        
        $status = $request->validate([
            'cpu' => 'nullable|numeric',
            'mem' => 'nullable|numeric',
            'net_in' => 'nullable|numeric',
            'net_out' => 'nullable|numeric'
        ]);
        
        $machine->status = json_encode($status);
        $machine->save();

        return response([
            'data' => 'success'
        ]);
    }

    public function fetchCommand(Request $request)
    {
        $machine = $this->authenticate($request);
        
        // Fetch pending deploy commands from Cache or Queue
        // For demonstration, we assume we use Cache with the machine token
        $cmd = \Illuminate\Support\Facades\Cache::pull('deploy_command_' . $machine->api_token);

        return response([
            'data' => $cmd ?: ''
        ]);
    }
}
