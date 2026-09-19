<?php

namespace App\Services;

use App\Jobs\PanelAgentRunJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class AgentRunService
{
    public const TTL = 86400;
    private function key(int $admin, string $kind, string $id): string { return "panel-agent:$admin:$kind:$id"; }
    private function read(int $admin, string $kind, string $id): ?array
    {
        $value = Cache::get($this->key($admin, $kind, $id));
        return $value ? json_decode(Crypt::decryptString($value), true) : null;
    }
    private function put(int $admin, string $kind, string $id, array $value): void
    {
        Cache::put($this->key($admin, $kind, $id), Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), self::TTL);
    }
    public function conversation(int $admin, string $id): array
    {
        $conversation = $this->read($admin, 'conversation', $id);
        abort_unless($conversation, 404, '会话已过期，请开始新对话');
        $conversation['turns'] = $this->receipts($admin, $conversation['turns']);
        $conversation['context'] = $this->context($conversation)['meta'];
        return $conversation;
    }
    public function context(array $conversation): array
    {
        $complete = array_values(array_filter($conversation['turns'], fn ($t) => $t['status'] === 'complete'));
        $recent = array_slice($complete, -8);
        $build = function ($turns) {
            $out = [];
            foreach ($turns as $turn) {
                $out[] = ['role' => 'user', 'content' => mb_substr($turn['prompt'], 0, 6000)];
                $reply = mb_substr($turn['reply'] ?? '', 0, 6000);
                if (!empty($turn['actions'])) {
                    $rows = [];
                    foreach ($turn['actions'] as $action) foreach (array_slice($action['rows'], 0, 40) as $row) $rows[] = array_intersect_key($row, array_flip(['key', 'name', 'after']));
                    $reply .= "\n此前预览对象（不代表当前执行状态，必须重新核对）：" . json_encode(array_slice($rows, 0, 40), JSON_UNESCAPED_UNICODE);
                }
                $out[] = ['role' => 'assistant', 'content' => $reply];
            }
            return $out;
        };
        while ($recent && strlen(json_encode($build($recent), JSON_UNESCAPED_UNICODE)) > 32000) array_shift($recent);
        $messages = $build($recent);
        return ['messages' => $messages, 'meta' => ['turns' => count($recent), 'max_turns' => 8, 'characters' => array_sum(array_map(fn ($m) => mb_strlen($m['content']), $messages)), 'omitted' => count($complete) - count($recent) + ($conversation['archived'] ?? 0), 'expires_in' => self::TTL]];
    }
    private function receipts(int $admin, array $turns): array
    {
        $tokens = [];
        foreach ($turns as $turn) foreach ($turn['actions'] ?? [] as $action) foreach ($action['approvals'] ?? [] as $approval) $tokens[] = $approval['token'];
        if (!$tokens) return $turns;
        $rows = \Illuminate\Support\Facades\DB::table('v2_agent_operation')->where('admin_id', $admin)->whereIn('token_hash', array_map(fn ($t) => hash('sha256', $t), $tokens))->get(['id', 'token_hash', 'status'])->keyBy('token_hash');
        $pending = Cache::many(array_map(fn ($t) => 'agent-action:' . $t, $tokens));
        $rejected = Cache::many(array_map(fn ($t) => 'agent-rejected:' . $t, $tokens));
        foreach ($turns as &$turn) {
            $turn['actions'] ??= [];
            foreach ($turn['actions'] as &$action) {
                $action['approvals'] ??= [];
                foreach ($action['approvals'] as &$approval) {
                    $row = $rows[hash('sha256', $approval['token'])] ?? null;
                    if ($row) { $approval['done'] = true; $approval['operation_id'] = (int) $row->id; $approval['undone'] = $row->status === 'undone'; }
                    elseif (($rejected['agent-rejected:' . $approval['token']] ?? null) === $admin) $approval['cancelled'] = true;
                    elseif (($action['expires_at'] ?? 0) > time() && empty($pending['agent-action:' . $approval['token']])) $approval['cancelled'] = true;
                }
            }
        }
        unset($turn, $action, $approval);
        return $turns;
    }
    public function start(int $admin, string $prompt, string $choice, string $clientId, ?string $conversationId): array
    {
        return Cache::lock($this->key($admin, 'lock', 'start'), 10)->block(2, function () use ($admin, $prompt, $choice, $clientId, $conversationId) {
            if ($old = $this->read($admin, 'run', $clientId)) {
                abort_unless($old['prompt'] === $prompt && $old['model_choice'] === $choice, 409, '重复请求标识与内容不一致');
                abort_if($conversationId && $conversationId !== $old['conversation_id'], 409, '请求标识已属于另一会话');
                return $this->snapshot($admin, $clientId);
            }
            $activeId = Cache::get($this->key($admin, 'active', 'current'));
            if ($activeId && $this->read($admin, 'run', $activeId)) {
                $active = $this->snapshot($admin, $activeId);
                abort_if(in_array($active['status'], ['queued', 'running']), 409, '已有请求执行中，请先等待或停止');
            }
            $config = app(PanelAgentService::class)->settings(true);
            abort_unless($config['enabled'], 409, '请先启用助手');
            abort_unless(AgentModelPolicy::candidates($config + ['model_choice' => $choice]), 409, '没有可用的模型配置');
            $conversationId = $conversationId ?: (string) Str::uuid();
            $conversation = $this->read($admin, 'conversation', $conversationId) ?? ['id' => $conversationId, 'turns' => [], 'archived' => 0, 'active_run' => null];
            $context = $this->context($conversation);
            $run = ['id' => $clientId, 'conversation_id' => $conversationId, 'prompt' => $prompt, 'model_choice' => $choice,
                'status' => 'queued', 'reply' => '', 'model' => null, 'actions' => [], 'steps' => [['type' => 'queued', 'label' => '等待后台执行', 'at' => time()]],
                'context' => $context['meta'], 'context_messages' => $context['messages'], 'created_at' => time(), 'started_at' => null, 'updated_at' => time(), 'error' => null];
            $conversation['active_run'] = $clientId;
            $this->put($admin, 'conversation', $conversationId, $conversation);
            $this->put($admin, 'run', $clientId, $run);
            Cache::put($this->key($admin, 'active', 'current'), $clientId, self::TTL);
            try { Queue::connection('agent')->push(new PanelAgentRunJob($admin, $clientId)); }
            catch (\Throwable $e) {
                $run['status'] = 'failed'; $run['error'] = '无法提交助手任务，请检查专用队列'; $this->finish($admin, $run);
            }
            return $this->snapshot($admin, $clientId);
        });
    }
    public function snapshot(int $admin, string $id): array
    {
        $run = $this->read($admin, 'run', $id);
        abort_unless($run, 404, '任务已过期或不属于当前管理员');
        if ($run['status'] === 'running' && time() - $run['updated_at'] > 240) {
            $run['status'] = 'failed'; $run['error'] = '后台执行进度已中断，请检查助手执行器'; $this->finish($admin, $run);
        }
        unset($run['context_messages']);
        if ($run['status'] === 'complete') $run = $this->receipts($admin, [$run])[0];
        return $run;
    }
    private function cancelled(int $admin, string $id): bool { return (bool) Cache::get($this->key($admin, 'cancel', $id)); }
    public function cancel(int $admin, string $id): array
    {
        $run = Cache::lock($this->key($admin, 'lock', $id), 10)->block(2, function () use ($admin, $id) {
            $run = $this->read($admin, 'run', $id); abort_unless($run, 404);
            if (in_array($run['status'], ['queued', 'running'])) {
                Cache::put($this->key($admin, 'cancel', $id), true, self::TTL);
                $run['status'] = 'cancelled'; $run['updated_at'] = time();
                $this->put($admin, 'run', $id, $run);
            }
            return $run;
        });
        if ($run['status'] === 'cancelled') $this->finish($admin, $run);
        unset($run['context_messages']); return $run;
    }
    private function finish(int $admin, array $run): void
    {
        Cache::lock($this->key($admin, 'lock', $run['id']), 10)->block(2, function () use ($admin, &$run) {
            if ($this->cancelled($admin, $run['id'])) { $run['status'] = 'cancelled'; $run['error'] = null; $run['actions'] = []; }
            $run['updated_at'] = time(); unset($run['context_messages']);
            $this->put($admin, 'run', $run['id'], $run);
            $conversation = $this->read($admin, 'conversation', $run['conversation_id']);
            if ($conversation) {
                $conversation['turns'] = array_values(array_filter($conversation['turns'], fn ($t) => $t['id'] !== $run['id']));
                $conversation['turns'][] = array_intersect_key($run, array_flip(['id', 'prompt', 'reply', 'model', 'actions', 'status', 'error', 'steps', 'created_at', 'updated_at']));
                while (count($conversation['turns']) > 16 || strlen(json_encode($conversation, JSON_UNESCAPED_UNICODE)) > 524288) { array_shift($conversation['turns']); $conversation['archived']++; }
                if ($conversation['active_run'] === $run['id']) $conversation['active_run'] = null;
                $this->put($admin, 'conversation', $run['conversation_id'], $conversation);
            }
            if (Cache::get($this->key($admin, 'active', 'current')) === $run['id']) Cache::forget($this->key($admin, 'active', 'current'));
        });
    }
    public function fail(int $admin, string $id, string $message): void
    {
        if ($run = $this->read($admin, 'run', $id)) { $run['status'] = 'failed'; $run['error'] = $message; $this->finish($admin, $run); }
    }
    public function perform(int $admin, string $id, PanelAgentService $agent, ?callable $keepAlive = null): void
    {
        $run = $this->read($admin, 'run', $id);
        if (!$run || !in_array($run['status'], ['queued', 'running']) || $this->cancelled($admin, $id)) return;
        $run['status'] = 'running'; $run['started_at'] = time(); $run['updated_at'] = time();
        $lastSave = 0; $lastStage = ''; $lastLease = 0;
        $emit = function (string $event, array $data) use ($admin, $id, &$run, &$lastSave, &$lastStage, &$lastLease, $keepAlive) {
            abort_if($this->cancelled($admin, $id), 499, '已停止');
            if ($keepAlive && microtime(true) - $lastLease >= 30) { $keepAlive(); $lastLease = microtime(true); }
            if ($event === 'text') { $run['reply'] .= $data['text']; $event = 'generating'; $data = ['label' => '正在生成回答']; }
            if ($event === 'reasoning') $data = ['label' => '模型正在推理'];
            if ($event === 'model') $run['model'] = $data['model'];
            if ($event !== 'heartbeat' && ($event !== $lastStage || $event === 'summary' || str_starts_with($event, 'tool_'))) {
                $run['steps'][] = ['type' => $event, 'label' => $data['label'] ?? '模型提供的推理摘要', 'tool' => $data['tool'] ?? null, 'count' => $data['count'] ?? null, 'summary' => $event === 'summary' ? $data['text'] : null, 'at' => time()];
                $run['steps'] = array_slice($run['steps'], -60); $lastStage = $event;
            }
            if (microtime(true) - $lastSave >= 0.25 || str_starts_with($event, 'tool_') || $event === 'model') {
                $run['updated_at'] = time();
                Cache::lock($this->key($admin, 'lock', $id), 10)->block(2, function () use ($admin, $id, &$run) {
                    abort_if($this->cancelled($admin, $id), 499, '已停止');
                    $this->put($admin, 'run', $id, $run);
                    if (Cache::get($this->key($admin, 'active', 'current')) === $id) Cache::put($this->key($admin, 'active', 'current'), $id, self::TTL);
                });
                $lastSave = microtime(true);
            }
        };
        try {
            $emit('started', ['label' => '开始处理，历史状态将重新核对']);
            $result = $agent->chat($run['prompt'], Request::create('/', 'POST', ['user' => ['id' => $admin], 'model_choice' => $run['model_choice']]), $emit, $run['context_messages']);
            $run['reply'] = $result['reply']; $run['actions'] = $result['actions'] ?? []; $run['model'] = $result['model'] ?? $run['model']; $run['status'] = 'complete';
            $run['steps'][] = ['type' => 'done', 'label' => $run['actions'] ? '预览完成，等待你批准' : '本轮处理完成', 'at' => time()];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $run['status'] = $e->getStatusCode() === 499 ? 'cancelled' : 'failed'; $run['error'] = $e->getStatusCode() === 499 ? null : mb_substr($e->getMessage(), 0, 600);
        } catch (\Throwable $e) {
            $run['status'] = 'failed'; $run['error'] = '助手执行失败，请重试；后台已记录错误类型';
            \Illuminate\Support\Facades\Log::warning('Agent run failed', ['id' => $id, 'type' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
        }
        $this->finish($admin, $run);
    }
}
