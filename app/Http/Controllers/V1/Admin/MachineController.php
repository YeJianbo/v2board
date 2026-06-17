<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MachineController extends Controller
{
    public function fetch(Request $request)
    {
        return response([
            'data' => Machine::orderBy('id', 'DESC')->get()
        ]);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required',
            'host' => 'nullable'
        ]);

        if ($request->input('id')) {
            $machine = Machine::findOrFail($request->input('id'));
            $machine->update($params);
        } else {
            $params['api_token'] = Str::random(32);
            Machine::create($params);
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $machine = Machine::findOrFail($request->input('id'));
        $machine->delete();
        return response([
            'data' => true
        ]);
    }

    // Generate deploy command for the specific machine
    public function deployCommand(Request $request)
    {
        $machine = Machine::findOrFail($request->input('id'));
        $nodeId = $request->input('node_id');
        $nodeType = $request->input('node_type');
        
        $panelUrl = config('v2board.app_url');
        // Command to be executed on the machine, our custom proprietary agent handles this
        $cmd = "v2agent deploy --token={$machine->api_token} --panel={$panelUrl} --node_id={$nodeId} --node_type={$nodeType}";
        
        \Illuminate\Support\Facades\Cache::put('deploy_command_' . $machine->api_token, $cmd, 300);

        return response([
            'data' => true,
            'message' => '指令已下发至探针队列'
        ]);
    }
}
