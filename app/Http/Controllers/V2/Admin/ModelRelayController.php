<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModelRelayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModelRelayController extends Controller
{
    public function quality(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['upstream_id' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1']);
        $runs = DB::table('v2_model_quality_run')->where('upstream_id', $d['upstream_id'])->orderByDesc('id')->paginate(10);
        foreach ($runs as $run) { $run->results = json_decode($run->results ?? '[]', true); unset($run->settings); }
        return $this->success($service->config($d['upstream_id']) + ['runs' => $runs]);
    }
    public function qualitySave(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['upstream_id' => 'required|integer|min:1', 'settings' => 'required|array']);
        return $this->success($service->save($d['upstream_id'], $d['settings']));
    }
    public function qualityRun(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['upstream_id' => 'required|integer|min:1']);
        return $this->success(['id' => $service->enqueue($d['upstream_id'])]);
    }
    public function qualityBaseline(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['run_id' => 'required|integer|min:1']); $service->baseline($d['run_id']);
        return $this->success(true);
    }
    public function status(ModelRelayService $service) { return $this->success($service->status()); }
    public function upstream(Request $request, ModelRelayService $service) { return $this->success(['id' => $service->upstream($request->all())]); }
    public function saveKey(Request $request, ModelRelayService $service) { return $this->success($service->saveKey($request->all())); }
    public function test(Request $request, ModelRelayService $service)
    {
        $data = $request->validate(['id' => 'required|integer|min:1']);
        return $this->success($service->testUpstream($data['id']));
    }
    public function keyAction(Request $request, ModelRelayService $service)
    {
        $d = $request->validate(['id' => 'required|integer', 'action' => 'required|in:rotate,revoke']);
        return $this->success(['key' => $service->keyAction($d['id'], $d['action'])]);
    }
    public function usage(Request $request)
    {
        $d = $request->validate(['machine_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1', 'status' => 'nullable|in:pending,complete,rejected,interrupted,unknown,estimated']);
        $query = DB::table('v2_model_usage')->orderByDesc('created_at')->orderByDesc('request_id');
        if (!empty($d['machine_id'])) $query->where('machine_id', $d['machine_id']);
        if (!empty($d['status'])) $query->where('status', $d['status']);
        return $this->success($query->paginate(25));
    }
}
