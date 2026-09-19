<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AgentOperationService
{
    public function receipt(string $token, int $adminId): ?array
    {
        $row = DB::table('v2_agent_operation')->where('token_hash', hash('sha256', $token))->where('admin_id', $adminId)->first(['id', 'node_count', 'status']);
        return $row ? $this->result($row) : null;
    }

    private function result(object $row): array
    {
        return ['operation_id' => (int) $row->id, 'updated' => (int) $row->node_count, 'status' => $row->status];
    }

    public function apply(string $token, int $adminId, string $tool, array $rows): array
    {
        abort_unless($rows && count($rows) <= 1000, 422, '没有可执行的变更，或变更数量超过 1000 条');
        return DB::transaction(function () use ($token, $adminId, $tool, $rows) {
            // 快照与字段修改共用事务，任何一方失败都会一起回滚。
            $id = DB::table('v2_agent_operation')->insertGetId([
                'admin_id' => $adminId, 'token_hash' => hash('sha256', $token), 'tool' => $tool,
                'node_count' => count($rows), 'snapshot' => Crypt::encryptString(json_encode($rows, JSON_THROW_ON_ERROR)),
                'created_at' => time(), 'status' => 'applied',
            ]);
            app(NodePresentationService::class)->apply($rows);
            return ['operation_id' => $id, 'updated' => count($rows), 'status' => 'applied'];
        });
    }

    public function history(int $adminId, int $page = 1): array
    {
        $records = DB::table('v2_agent_operation')->where('admin_id', $adminId)
            ->select('id', 'tool', 'node_count', 'status', 'created_at', 'undone_at')
            ->orderByDesc('id')->paginate(15, ['*'], 'page', $page);
        return $records->toArray();
    }

    private function find(int $id, int $adminId, bool $lock = false): object
    {
        $query = DB::table('v2_agent_operation')->where('id', $id)->where('admin_id', $adminId);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        abort_unless($row, 404, '操作记录不存在');
        return $row;
    }

    public function detail(int $id, int $adminId): array
    {
        $operation = $this->find($id, $adminId);
        $rows = json_decode(Crypt::decryptString($operation->snapshot), true, 512, JSON_THROW_ON_ERROR);
        return $this->result($operation) + ['tool' => $operation->tool, 'created_at' => $operation->created_at, 'undone_at' => $operation->undone_at,
            'rows' => array_map(fn ($row) => array_intersect_key($row, array_flip(['key', 'name', 'field', 'before', 'after', 'port'])), $rows)];
    }

    public function undoPreview(int $id, int $adminId): array
    {
        $operation = $this->find($id, $adminId);
        abort_unless($operation->status === 'applied', 409, '该操作已经撤销');
        $reverse = $this->reverse($operation);
        app(NodePresentationService::class)->assertCurrent($reverse);
        return ['operation_id' => $id, 'rows' => array_map(fn ($row) => array_intersect_key($row, array_flip(['key', 'name', 'field', 'before', 'after', 'port'])), $reverse)];
    }

    private function reverse(object $operation): array
    {
        abort_if($operation->tool === 'preview_machine_provision',422,'新建节点不支持字段撤销，请在机器节点页核对后删除；不会自动删除可能已被使用的节点');
        $rows = json_decode(Crypt::decryptString($operation->snapshot), true, 512, JSON_THROW_ON_ERROR);
        return array_map(function ($row) {
            $reverse = $row;
            $reverse['expected'] = $row['after'];
            $reverse['before'] = $row['after'];
            $reverse['after'] = $row['expected'];
            return $reverse;
        }, $rows);
    }

    public function undo(int $id, int $adminId): array
    {
        // 撤销不依赖模型在线或 Agent 启用状态，管理员可随时处理已执行的操作。
        return DB::transaction(function () use ($id, $adminId) {
            $operation = $this->find($id, $adminId, true);
            if ($operation->status === 'undone') return $this->result($operation);
            app(NodePresentationService::class)->apply($this->reverse($operation));
            DB::table('v2_agent_operation')->where('id', $id)->update(['status' => 'undone', 'undone_at' => time(), 'undone_by' => $adminId]);
            return ['operation_id' => $id, 'updated' => (int) $operation->node_count, 'status' => 'undone'];
        });
    }
}
