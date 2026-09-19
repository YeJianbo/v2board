<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerTuic;
use Illuminate\Http\Request;

class TuicController extends Controller
{
    public function save(Request $request)
    {
        $metadata = $this->validateServerMetadata($request);
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1',
            'disable_sni' => 'required|in:0,1',
            'udp_relay_mode' => 'nullable',
            'zero_rtt_handshake' => 'required|in:0,1',
            'congestion_control' => 'nullable'
        ]);

        if ($request->input('id')) {
            $server = ServerTuic::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
                $this->saveServerMetadata('tuic', (int) $server->id, $metadata);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        $server = ServerTuic::create($params);
        if (!$server) {
            abort(500, '创建失败');
        }
        $this->saveServerMetadata('tuic', (int) $server->id, $metadata);

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerTuic::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        $deleted = $server->delete();
        if ($deleted) {
            $this->deleteServerMetadata('tuic', (int) $server->id);
        }
        return response(['data' => $deleted]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1'
        ], [
            'show.in' => '显示状态格式不正确'
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerTuic::find($request->input('id'));

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
        $server = ServerTuic::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }
        $server->show = 0;
        $copiedServer = ServerTuic::create($server->toArray());
        if (!$copiedServer) {
            abort(500, '复制失败');
        }
        $this->copyServerMetadata('tuic', (int) $server->id, (int) $copiedServer->id);

        return response([
            'data' => true
        ]);
    }
}
