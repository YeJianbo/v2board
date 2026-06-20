<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Log as LogModel;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use App\Helpers\ResponseEnum;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;

class SystemController extends Controller
{
    public function getSystemStatus()
    {
        $data = [
            'schedule' => $this->getScheduleStatus(),
            'horizon' => $this->getHorizonStatus(),
            'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
        ];
        return $this->success($data);
    }

    public function getQueueWorkload()
    {
        try {
            $data = collect(app(WorkloadRepository::class)->get())
                ->sortBy('name')
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            $data = [];
        }

        return $this->success($data);
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        try {
            if (! $masters = app(MasterSupervisorRepository::class)->all()) {
                return false;
            }

            return collect($masters)->contains(function ($master) {
                return $master->status === 'paused';
            }) ? false : true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getQueueStats()
    {
        $data = $this->emptyQueueStats();

        try {
            $data = [
                'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
                'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
                'pausedMasters' => $this->totalPausedMasters(),
                'periods' => [
                    'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                    'recentJobs' => config('horizon.trim.recent'),
                ],
                'processes' => $this->totalProcessCount(),
                'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
                'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
                'recentJobs' => app(JobRepository::class)->countRecent(),
                'status' => $this->getHorizonStatus(),
                'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
            ];
        } catch (\Throwable $e) {
            $data['status'] = false;
            $data['unavailable'] = true;
            $data['message'] = $e->getMessage();
        }

        return $this->success($data);
    }

    private function emptyQueueStats(): array
    {
        return [
            'failedJobs' => 0,
            'jobsPerMinute' => 0,
            'pausedMasters' => 0,
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => 0,
            'queueWithMaxRuntime' => null,
            'queueWithMaxThroughput' => null,
            'recentJobs' => 0,
            'status' => false,
            'wait' => collect(),
        ];
    }

    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    protected function totalPausedMasters()
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    public function getAuditLog(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 10));

        $builder = LogModel::orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->when($request->input('level'), fn($q, $v) => $q->where('level', $v))
            ->when($request->input('action'), fn($q, $v) => $q->where('method', $v))
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(function ($q) use ($keyword) {
                    $q->where('uri', 'like', '%' . $keyword . '%')
                      ->orWhere('title', 'like', '%' . $keyword . '%')
                      ->orWhere('data', 'like', '%' . $keyword . '%')
                      ->orWhere('context', 'like', '%' . $keyword . '%');
                });
            });

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'level' => $item->level,
                    'host' => $item->host,
                    'uri' => $item->uri,
                    'method' => $item->method,
                    'data' => $item->data,
                    'ip' => $item->ip,
                    'context' => $item->context,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            })
            ->values();

        return response(['data' => $res, 'total' => $total]);
    }

    public function getHorizonFailedJobs(Request $request)
    {
        return response()->json([
            'data' => [],
            'total' => 0,
            'current' => 1,
            'page_size' => 20,
        ]);
    }

}
