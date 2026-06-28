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
        abort(410, 'Remote command execution is disabled');
    }
}
