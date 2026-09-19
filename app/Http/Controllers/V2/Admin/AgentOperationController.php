<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AgentOperationController extends Controller
{
    public function history(Request $request, AgentOperationService $service)
    {
        $params = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        return $this->success($service->history((int) $request->input('user.id'), $params['page'] ?? 1));
    }

    public function detail(Request $request, AgentOperationService $service)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        return $this->success($service->detail($params['id'], (int) $request->input('user.id')));
    }

    public function undoPreview(Request $request, AgentOperationService $service)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $adminId = (int) $request->input('user.id');
        $result = $service->undoPreview($params['id'], $adminId);
        $token = (string) Str::uuid();
        Cache::put('agent-undo:' . $token, ['admin_id' => $adminId, 'operation_id' => $params['id']], 300);
        return $this->success($result + ['token' => $token, 'expires_at' => time() + 300]);
    }

    public function undo(Request $request, AgentOperationService $service)
    {
        $params = $request->validate(['token' => 'required|uuid']);
        $preview = Cache::get('agent-undo:' . $params['token']);
        $adminId = (int) $request->input('user.id');
        abort_unless(is_array($preview) && $preview['admin_id'] === $adminId, 409, '撤销预览已过期，请重新查看');
        $result = $service->undo($preview['operation_id'], $adminId);
        info('Agent operation undone', ['admin_id' => $adminId, 'operation_id' => $preview['operation_id']]);
        return $this->success($result);
    }

    public function cancel(Request $request)
    {
        $params = $request->validate(['token' => 'required|uuid']);
        return Cache::lock('agent-write:' . (int) $request->input('user.id'), 60)->block(3, function () use ($params, $request) {
            $key = 'agent-action:' . $params['token'];
            $action = Cache::get($key);
            abort_unless(is_array($action) && $action['admin_id'] === (int) $request->input('user.id'), 409, '预览已失效');
            Cache::put('agent-rejected:' . $params['token'], (int) $request->input('user.id'), \App\Services\AgentRunService::TTL);
            Cache::forget($key);
            return $this->success(['cancelled' => true]);
        });
    }
}
