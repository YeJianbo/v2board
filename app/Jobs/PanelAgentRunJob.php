<?php

namespace App\Jobs;

use App\Services\AgentRunService;
use App\Services\PanelAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class PanelAgentRunJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;
    public $tries = 1;
    public $timeout = 0;
    public int $admin;
    public string $run;
    public function __construct(int $admin, string $run) { $this->admin = $admin; $this->run = $run; $this->onConnection('agent'); $this->onQueue('panel_agent'); }
    public function handle(AgentRunService $runs, PanelAgentService $agent): void
    {
        $user = \App\Models\User::find($this->admin);
        if (!$user || !$user->is_admin || $user->banned) { $runs->fail($this->admin, $this->run, '当前管理员权限已失效'); return; }
        $runs->perform($this->admin, $this->run, $agent, function () {
            if (!$this->job instanceof \Illuminate\Queue\Jobs\RedisJob) return;
            $queue = $this->job->getRedisQueue();
            // Renew only our existing reservation; never resurrect an already reclaimed job.
            $renewed = $queue->getConnection()->eval(
                "if redis.call('ZSCORE', KEYS[1], ARGV[1]) then redis.call('ZADD', KEYS[1], ARGV[2], ARGV[1]); return 1 else return 0 end",
                1, $queue->getQueue($this->job->getQueue()) . ':reserved', $this->job->getReservedJob(), time() + (int) config('queue.connections.agent.retry_after', 240)
            );
            abort_unless((int) $renewed === 1, 409, '后台任务执行权已失效，本轮已停止');
        });
    }
    public function failed(\Throwable $e): void { app(AgentRunService::class)->fail($this->admin, $this->run, '后台执行器中断，可重试本轮'); }
}
