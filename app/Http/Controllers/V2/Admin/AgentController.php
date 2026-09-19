<?php
namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\PanelAgentService;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function start(Request $request, \App\Services\AgentRunService $runs)
    {
        $d = $request->validate(['message' => 'required|string|max:6000', 'model_choice' => 'nullable|string|max:80', 'client_id' => 'required|uuid', 'conversation_id' => 'nullable|uuid']);
        return $this->success($runs->start((int) $request->input('user.id'), $d['message'], $d['model_choice'] ?? 'auto', $d['client_id'], $d['conversation_id'] ?? null));
    }
    public function run(Request $request, \App\Services\AgentRunService $runs)
    {
        $d = $request->validate(['id' => 'required|uuid']);
        return $this->success($runs->snapshot((int) $request->input('user.id'), $d['id']));
    }
    public function cancelRun(Request $request, \App\Services\AgentRunService $runs)
    {
        $d = $request->validate(['id' => 'required|uuid']);
        return $this->success($runs->cancel((int) $request->input('user.id'), $d['id']));
    }
    public function conversation(Request $request, \App\Services\AgentRunService $runs)
    {
        $d = $request->validate(['id' => 'required|uuid']);
        return $this->success($runs->conversation((int) $request->input('user.id'), $d['id']));
    }
    public function settings(PanelAgentService $service) { return $this->success(['config' => $service->settings(), 'tools' => PanelAgentService::TOOLS]); }
    public function save(Request $request, PanelAgentService $service) { return $this->success($service->save($request->all())); }
    public function chat(Request $request, PanelAgentService $service)
    {
        $data = $request->validate(['message' => 'required|string|max:6000', 'model_choice' => 'nullable|string|max:80']);
        try {
            return $this->success($service->chat($data['message'], $request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() === 403) return response()->json(['message' => $e->getMessage(), 'code' => 'agent_permission_denied'], 409);
            if ($e->getStatusCode() !== 502) throw $e;
            // 明确的应用层错误，避免边缘代理把 502 换成通用 HTML 页面。
            return response()->json(['message' => $e->getMessage(), 'code' => 'agent_upstream_unavailable'], 503);
        }
    }
    public function confirm(Request $request, PanelAgentService $service)
    {
        $data = $request->validate(['token' => 'required|uuid']);
        try {
            return $this->success($service->confirmWithReceipt($data['token'], $request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) throw $e;
            return response()->json(['message' => $e->getMessage(), 'code' => 'agent_permission_denied'], 409);
        }
    }
    public function relayStatus()
    {
        return $this->success(['enabled' => false, 'phase' => 'reserved', 'scope' => 'per-machine', 'protocols' => ['openai-compatible'], 'features' => ['machine_keys', 'per_machine_quota', 'usage_ledger'], 'supported_upstreams' => ['CLIProxyAPI', 'Sub2API']]);
    }
    public function confirmMany(Request $request, PanelAgentService $service)
    {
        $data = $request->validate(['tokens' => 'required|array|min:1|max:800', 'tokens.*' => 'required|uuid|distinct']);
        try {
            return $this->success($service->confirmBatch($data['tokens'], $request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) throw $e;
            return response()->json(['message' => $e->getMessage(), 'code' => 'agent_permission_denied'], 409);
        }
    }
}
