<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\ServerGroup;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public static function encodeId($id, $type)
    {
        $prefixes = [
            'shadowsocks' => 1000000,
            'vmess' => 2000000,
            'trojan' => 3000000,
            'tuic' => 4000000,
            'hysteria' => 5000000,
            'vless' => 6000000,
            'anytls' => 7000000,
            'v2node' => 8000000,
        ];
        return ($prefixes[strtolower($type)] ?? 0) + $id;
    }

    public static function decodeId($encodedId)
    {
        $prefixes = [
            'shadowsocks' => 1000000,
            'vmess' => 2000000,
            'trojan' => 3000000,
            'tuic' => 4000000,
            'hysteria' => 5000000,
            'vless' => 6000000,
            'anytls' => 7000000,
            'v2node' => 8000000,
        ];
        foreach ($prefixes as $type => $prefix) {
            if ($encodedId >= $prefix && $encodedId < $prefix + 1000000) {
                return [
                    'id' => $encodedId - $prefix,
                    'type' => $type
                ];
            }
        }
        return [
            'id' => $encodedId,
            'type' => null
        ];
    }

    private function getServerModelClass($type)
    {
        $classes = [
            'shadowsocks' => \App\Models\ServerShadowsocks::class,
            'vmess' => \App\Models\ServerVmess::class,
            'trojan' => \App\Models\ServerTrojan::class,
            'tuic' => \App\Models\ServerTuic::class,
            'hysteria' => \App\Models\ServerHysteria::class,
            'vless' => \App\Models\ServerVless::class,
            'anytls' => \App\Models\ServerAnytls::class,
            'v2node' => \App\Models\ServerV2node::class,
        ];
        return $classes[strtolower($type)] ?? null;
    }

    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        $servers = collect($serverService->getAllServers())->map(function ($item) {
            $rawId = $item['id'];
            $type = $item['type'];
            $encodedId = self::encodeId($rawId, $type);
            $item['id'] = $encodedId;
            if (isset($item['parent_id']) && $item['parent_id']) {
                $item['parent_id'] = self::encodeId($item['parent_id'], $type);
            }
            $item['groups'] = ServerGroup::whereIn('id', $item['group_id'] ?? [])->get(['name', 'id']);
            $item['group_ids'] = array_map('strval', $item['group_id'] ?? []);
            $item['route_ids'] = array_map('strval', $item['route_id'] ?? []);
            
            $item['parent'] = null;
            if (isset($item['parent_id']) && $item['parent_id']) {
                $modelClass = $this->getServerModelClass($type);
                if ($modelClass) {
                    $parent = $modelClass::find($item['parent_id']);
                    if ($parent) {
                        $parentArray = $parent->toArray();
                        $parentArray['id'] = self::encodeId($parentArray['id'], $type);
                        $item['parent'] = $parentArray;
                    }
                }
            }
            return $item;
        })->toArray();
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    $decoded = self::decodeId($item['id']);
                    if ($decoded['type']) {
                        $modelClass = $this->getServerModelClass($decoded['type']);
                        if ($modelClass) {
                            $modelClass::where('id', $decoded['id'])->update(['sort' => $item['order']]);
                        }
                    }
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        $type = $request->input('type');
        $modelClass = $this->getServerModelClass($type);
        if (!$modelClass) {
            return $this->fail([400, '不支持的节点类型']);
        }

        if (isset($params['group_ids'])) {
            $params['group_id'] = $params['group_ids'];
            unset($params['group_ids']);
        }
        if (isset($params['route_ids'])) {
            $params['route_id'] = $params['route_ids'];
            unset($params['route_ids']);
        }

        if (isset($params['parent_id']) && $params['parent_id']) {
            $decodedParent = self::decodeId((int)$params['parent_id']);
            $params['parent_id'] = $decodedParent['id'];
        }

        if ($request->input('id')) {
            $decoded = self::decodeId((int)$request->input('id'));
            $server = $modelClass::find($decoded['id']);
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            $modelClass::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        $decoded = self::decodeId($request->id);
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        if (array_key_exists('show', $params)) {
            $server->show = (int) $params['show'];
        }
        if (array_key_exists('machine_id', $params)) {
            $server->machine_id = $params['machine_id'] ?: null;
        }
        if (array_key_exists('enabled', $params)) {
            $server->enabled = (bool) $params['enabled'];
        }

        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $decoded = self::decodeId($request->id);
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            DB::transaction(function () use ($ids) {
                foreach ($ids as $encodedId) {
                    $decoded = self::decodeId($encodedId);
                    $modelClass = $this->getServerModelClass($decoded['type']);
                    if ($modelClass) {
                        $modelClass::where('id', $decoded['id'])->delete();
                    }
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $decoded = self::decodeId($request->id);
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            DB::transaction(function () use ($ids) {
                foreach ($ids as $encodedId) {
                    $decoded = self::decodeId($encodedId);
                    $modelClass = $this->getServerModelClass($decoded['type']);
                    if ($modelClass) {
                        $modelClass::where('id', $decoded['id'])->update([
                            'u' => 0,
                            'd' => 0,
                        ]);
                    }
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            DB::transaction(function () use ($ids, $update) {
                foreach ($ids as $encodedId) {
                    $decoded = self::decodeId($encodedId);
                    $modelClass = $this->getServerModelClass($decoded['type']);
                    if ($modelClass) {
                        $modelClass::where('id', $decoded['id'])->update($update);
                    }
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    public function copy(Request $request)
    {
        $decoded = self::decodeId($request->input('id'));
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        if (array_key_exists('code', $copiedServer->getAttributes())) {
            $copiedServer->code = null;
        }
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        $contents = '';
        $contents .= pack('C', $configId);
        $contents .= pack('n', 0x0020);
        $contents .= pack('n', 32) . $publicKey;
        $contents .= pack('n', 8);
        $contents .= pack('nn', 0x0001, 0x0001);
        $contents .= pack('nn', 0x0001, 0x0003);
        $contents .= pack('C', 0);
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);

        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }
}
