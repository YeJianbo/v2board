<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerTrojanSave;
use App\Http\Requests\Admin\ServerTrojanUpdate;
use App\Models\ServerTrojan;
use App\Services\ServerService;
use Illuminate\Http\Request;

class TrojanController extends Controller
{
    public function save(ServerTrojanSave $request)
    {
        $metadata = $this->validateServerMetadata($request);
        $params = $request->validated();
        if ($request->input('id')) {
            $server = ServerTrojan::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
                $this->saveServerMetadata('trojan', (int) $server->id, $metadata);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        $server = ServerTrojan::create($params);
        if (!$server) {
            abort(500, '创建失败');
        }
        $this->saveServerMetadata('trojan', (int) $server->id, $metadata);

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerTrojan::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        $deleted = $server->delete();
        if ($deleted) {
            $this->deleteServerMetadata('trojan', (int) $server->id);
        }
        return response(['data' => $deleted]);
    }

    public function update(ServerTrojanUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerTrojan::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerTrojan::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }
        $server->show = 0;
        $copiedServer = ServerTrojan::create($server->toArray());
        if (!$copiedServer) {
            abort(500, '复制失败');
        }
        $this->copyServerMetadata('trojan', (int) $server->id, (int) $copiedServer->id);

        return response([
            'data' => true
        ]);
    }
}
