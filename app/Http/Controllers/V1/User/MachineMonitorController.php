<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicStatusController;
use App\Models\User;
use App\Models\ServerMetadata;
use App\Services\UserService;
use Illuminate\Http\Request;

class MachineMonitorController extends Controller
{
    public static function allowedMachineIds(array $nodes, int $groupId): array
    {
        if ($groupId <= 0) return [];
        $byId = array_column($nodes, null, 'id');
        $ids = [];
        foreach ($nodes as $node) {
            if (empty($node['show']) || !in_array($groupId, $node['group_id'] ?? [])) continue;
            $parent = $byId[$node['parent_id'] ?? 0] ?? [];
            foreach (['machine_id', 'relay_machine_id', 'entry_machine_id'] as $field) {
                $id = (int) ($node[$field] ?? 0);
                if ($field === 'machine_id' && !$id) $id = (int) ($parent[$field] ?? 0);
                if ($id > 0) $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public function fetch(Request $request)
    {
        $user = User::find($request->input('user.id'));
        abort_unless($user && !$user->banned, 403);
        $ids = [];
        if ((new UserService())->isAvailable($user)) {
            $entries = ServerMetadata::whereNotNull('entry_machine_id')->get()->keyBy(fn ($entry) => $entry->server_type . ':' . $entry->server_id);
            foreach (['ServerShadowsocks', 'ServerVmess', 'ServerTrojan', 'ServerTuic',
                'ServerHysteria', 'ServerVless', 'ServerAnytls', 'ServerV2node'] as $name) {
                $class = 'App\\Models\\' . $name;
                $type = strtolower(substr($name, 6));
                $nodes = $class::all()->toArray();
                foreach ($nodes as &$node) {
                    $node['entry_machine_id'] = $entries[$type . ':' . $node['id']]->entry_machine_id ?? null;
                }
                unset($node);
                $ids = array_merge($ids, self::allowedMachineIds($nodes, (int) $user->group_id));
            }
        }
        $public = app(PublicStatusController::class)->data()->getData(true)['data'];
        $visible = array_values(array_filter($public, fn ($row) => in_array((int) $row['id'], $ids, true)));
        $fields = array_flip(['id', 'name', 'country', 'country_code', 'is_online', 'cpu', 'mem', 'system', 'load', 'net_in', 'net_out']);
        $visible = array_map(fn ($row) => array_intersect_key($row, $fields), $visible);
        return response()->json(['data' => $visible])->header('Cache-Control', 'private, no-store');
    }
}
