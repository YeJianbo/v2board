<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Services\ModelRelayService;
use Illuminate\Http\Request;

class ModelRelayInternalController extends Controller
{
    public function handle(Request $request, ModelRelayService $service, string $operation)
    {
        $path = storage_path('app/integrations/model-relay-gateway.key');
        $expected = is_file($path) ? trim(file_get_contents($path)) : '';
        abort_unless(strlen($expected) >= 32 && hash_equals($expected, (string) $request->header('X-Relay-Secret')), 403);
        if ($operation === 'models') return response()->json(['models' => $service->models((string) $request->input('key'), $request->input('protocol'))]);
        if ($operation === 'connect') return response()->json($service->connection((string) $request->input('key'), (string) $request->input('protocol')));
        if ($operation === 'reserve') return response()->json($service->reserve($request->all()));
        if ($operation === 'settle') { $service->settle($request->all()); return response()->json(['ok' => true]); }
        if ($operation === 'upstream') {
            $data = $request->validate(['id' => 'required|integer|min:1']);
            return response()->json($service->upstreamCredentials($data['id']));
        }
        abort(404);
    }
}
