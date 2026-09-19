<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerVmessSave;
use App\Http\Requests\Admin\ServerVmessUpdate;
use App\Models\ServerVmess;
use Illuminate\Http\Request;

class VmessController extends Controller
{
    public function save(ServerVmessSave $request)
    {
        $metadata = $this->validateServerMetadata($request);
        $params = $request->validated();

        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
                $this->saveServerMetadata('vmess', (int) $server->id, $metadata);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        $server = ServerVmess::create($params);
        if (!$server) {
            abort(500, '创建失败');
        }
        $this->saveServerMetadata('vmess', (int) $server->id, $metadata);

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        $deleted = $server->delete();
        if ($deleted) {
            $this->deleteServerMetadata('vmess', (int) $server->id);
        }
        return response(['data' => $deleted]);
    }

    public function update(ServerVmessUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerVmess::find($request->input('id'));

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
        $server = ServerVmess::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }
        $server->show = 0;
        $copiedServer = ServerVmess::create($server->toArray());
        if (!$copiedServer) {
            abort(500, '复制失败');
        }
        $this->copyServerMetadata('vmess', (int) $server->id, (int) $copiedServer->id);

        return response([
            'data' => true
        ]);
    }
}
