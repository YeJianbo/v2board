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
        $token = $request->input('token');
        if (!$token) {
            abort(401, 'Unauthorized: No token provided');
        }
        $machine = Machine::where('api_token', $token)->first();
        if (!$machine) {
            abort(401, 'Unauthorized: Invalid token');
        }

        // IP Whitelist Check (Security Enhancement)
        $clientIp = $request->ip();
        // Allow if machine->host is empty (not restricted) or if it matches the client IP.
        // For strict security, we force it to match. We can split by comma if multiple IPs are allowed.
        if (!empty($machine->host)) {
            $allowedIps = array_map('trim', explode(',', $machine->host));
            if (!in_array($clientIp, $allowedIps)) {
                abort(401, "Unauthorized: IP {$clientIp} is not in whitelist");
            }
        } else {
            // Optional: If no host is set, we could reject. But for flexibility, we allow it.
            // We can log this event.
        }

        return $machine;
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
