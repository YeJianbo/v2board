<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionEntryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SubscriptionEntryController extends Controller
{
    public function preview(Request $request, SubscriptionEntryService $service)
    {
        $params = $request->validate(['machine_id' => 'required|integer|exists:v2_machine,id', 'host' => 'nullable|ip']);
        $rows = $service->preview((int) $params['machine_id'], $params['host'] ?? null);
        if (empty($params['host'])) return $this->success(['token'=>null,'rows'=>array_map(fn($row)=>array_intersect_key($row,array_flip(['key','name','port','before','after'])),$rows)]);
        $token = (string) Str::uuid();
        Cache::put('entry-preview:' . $token, ['admin_id' => (int) $request->input('user.id'), 'machine_id' => (int) $params['machine_id'], 'host' => $params['host'], 'rows' => $rows], 300);
        return $this->success(['token' => $token, 'rows' => array_map(fn ($row) => array_intersect_key($row, array_flip(['key', 'name', 'port', 'before', 'after'])), $rows)]);
    }

    public function apply(Request $request, SubscriptionEntryService $service)
    {
        $params = $request->validate(['token' => 'required|uuid']);
        $key = 'entry-preview:' . $params['token'];
        $preview = Cache::get($key);
        abort_unless(is_array($preview) && $preview['admin_id'] === (int) $request->input('user.id'), 409, '预览已失效，请重新预览');
        $count = $service->commit($preview['machine_id'], $preview['host'], $preview['rows']);
        Cache::forget($key);
        return $this->success(['updated' => $count]);
    }
}
