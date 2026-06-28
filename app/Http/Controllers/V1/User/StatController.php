<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use App\Models\StatUserServer;
use App\Models\StatUserServerHour;
use App\Models\StatUserServerMinute;
use Illuminate\Http\Request;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $builder = StatUser::select([
            'u',
            'd',
            'record_at',
            'user_id',
            'server_rate'
        ])
            ->where('user_id', $request->user['id'])
            ->where('record_at', '>=', strtotime(date('Y-m-1')))
            ->orderBy('record_at', 'DESC');
        return response([
            'data' => $builder->get()
        ]);
    }

    public function getNodeTrafficLog(Request $request)
    {
        $request->validate([
            'period' => 'nullable|in:minute,hour,day',
            'granularity' => 'nullable|in:minute,hour,day',
            'start_at' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_at' => 'nullable|integer|min:1000000000|max:9999999999',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'server_id' => 'nullable|integer|min:1',
            'server_type' => 'nullable|string|max:32',
            'node_keys' => 'nullable|array',
            'node_keys.*' => 'nullable|string|max:64',
            'include_total' => 'nullable|boolean',
        ]);

        $period = (string) ($request->input('period') ?: $request->input('granularity') ?: 'day');
        $includeTotal = $request->boolean('include_total', true);

        [$model, $step, $format, $startAt, $endAt, $note] = $this->resolveTrafficPeriod($period, $request);

        $baseQuery = $model
            ->where('user_id', $request->user['id'])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<=', $endAt);

        if ($request->input('server_id')) {
            $baseQuery->where('server_id', (int) $request->input('server_id'));
        }
        if ($request->input('server_type')) {
            $baseQuery->where('server_type', strtolower((string) $request->input('server_type')));
        }

        $requestedNodePairs = $this->parseNodeKeys($request->input('node_keys', []));
        if ($requestedNodePairs->isNotEmpty()) {
            $baseQuery->where(function ($query) use ($requestedNodePairs) {
                foreach ($requestedNodePairs as $pair) {
                    $query->orWhere(function ($subQuery) use ($pair) {
                        $subQuery->where('server_type', $pair['type'])
                            ->where('server_id', $pair['id']);
                    });
                }
            });
        }

        $logs = (clone $baseQuery)
            ->selectRaw('server_id, server_type as node_type, record_at, MAX(server_rate) as rate, SUM(u) as u, SUM(d) as d, SUM((u + d) * server_rate) as cost')
            ->groupBy('server_id', 'server_type', 'record_at')
            ->orderBy('record_at', 'DESC')
            ->get();

        $summaryRows = (clone $baseQuery)
            ->selectRaw('server_id as id, server_type, MAX(server_rate) as rate, SUM(u) as u, SUM(d) as d, SUM(u + d) as total, SUM((u + d) * server_rate) as cost')
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->get();

        $names = $this->resolveNodeNames($summaryRows->merge($logs));
        $protocols = $this->resolveNodeProtocols($summaryRows->merge($logs));

        $logs->transform(function ($item) use ($names, $protocols) {
            $key = $this->buildNodeKey($item->node_type ?? null, $item->server_id);
            $item->type = (string) ($protocols[$key] ?? $item->node_type);
            $item->name = (string) ($names[$key] ?? ('Node ' . $item->server_id));
            $item->total = (int) $item->u + (int) $item->d;
            $item->cost = (float) $item->cost;
            return $item;
        });

        $summary = $summaryRows->map(function ($row) use ($names, $protocols) {
            $key = $this->buildNodeKey($row->server_type ?? null, $row->id);
            return [
                'key' => $key,
                'id' => (int) $row->id,
                'server_id' => (int) $row->id,
                'server_type' => (string) $row->server_type,
                'type' => (string) ($protocols[$key] ?? $row->server_type),
                'name' => (string) ($names[$key] ?? ('Node ' . $row->id)),
                'rate' => (float) $row->rate,
                'u' => (int) $row->u,
                'd' => (int) $row->d,
                'total' => (int) $row->total,
                'cost' => (float) $row->cost,
            ];
        })->values();

        $series = $this->buildTrafficSeries(
            $logs,
            $summary,
            $requestedNodePairs,
            $includeTotal,
            $startAt,
            $endAt,
            $step,
            $format
        );

        return response([
            'data' => $logs,
            'summary' => $summary,
            'series' => $series,
            'nodes' => $summary,
            'meta' => [
                'period' => $period,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'note' => $logs->isEmpty() ? ($note ?: '暂无节点维度流量数据。') : $note,
            ],
        ]);
    }

    private function resolveTrafficPeriod(string $period, Request $request): array
    {
        $endInput = (int) ($request->input('end_at') ?: $request->input('end_time') ?: time());
        $startInput = (int) ($request->input('start_at') ?: $request->input('start_time') ?: 0);

        if ($period === 'minute') {
            $endAt = strtotime(date('Y-m-d H:i:00', $endInput));
            $startAt = $startInput ? strtotime(date('Y-m-d H:i:00', $startInput)) : $endAt - 59 * 60;
            $maxRange = 24 * 3600;
            $note = '分钟曲线从新版部署后开始统计。';
            $result = [StatUserServerMinute::query(), 60, 'H:i', $startAt, $endAt, $note];
        } elseif ($period === 'hour') {
            $endAt = strtotime(date('Y-m-d H:00:00', $endInput));
            $startAt = $startInput ? strtotime(date('Y-m-d H:00:00', $startInput)) : $endAt - 47 * 3600;
            $maxRange = 31 * 24 * 3600;
            $note = '小时曲线从新版部署后开始统计。';
            $result = [StatUserServerHour::query(), 3600, 'm-d H:00', $startAt, $endAt, $note];
        } else {
            $endAt = strtotime(date('Y-m-d', $endInput));
            $startAt = $startInput ? strtotime(date('Y-m-d', $startInput)) : $endAt - 29 * 86400;
            $maxRange = 366 * 86400;
            $note = null;
            $result = [StatUserServer::query(), 86400, 'm-d', $startAt, $endAt, $note];
        }

        if ($startAt > $endAt) {
            [$startAt, $endAt] = [$endAt, $startAt];
        }
        if (($endAt - $startAt) > $maxRange) {
            $startAt = $endAt - $maxRange;
        }

        $result[3] = $startAt;
        $result[4] = $endAt;
        return $result;
    }

    private function parseNodeKeys($nodeKeys)
    {
        return collect($nodeKeys)
            ->map(function ($value) {
                [$type, $id] = array_pad(explode(':', strtolower(trim((string) $value)), 2), 2, null);
                $id = (int) $id;
                if (!$type || $id <= 0) {
                    return null;
                }
                return [
                    'key' => $type . ':' . $id,
                    'type' => $type,
                    'id' => $id,
                ];
            })
            ->filter()
            ->values();
    }

    private function buildTrafficSeries($logs, $summary, $requestedNodePairs, bool $includeTotal, int $startAt, int $endAt, int $step, string $format): array
    {
        $labels = [];
        for ($cursor = $startAt; $cursor <= $endAt; $cursor += $step) {
            $labels[] = [
                'timestamp' => $cursor,
                'label' => date($format, $cursor),
            ];
        }

        $availableKeys = $summary->pluck('key')->values();
        $keysToRender = $requestedNodePairs->isNotEmpty()
            ? $requestedNodePairs->pluck('key')->filter(function ($key) use ($availableKeys) {
                return $availableKeys->contains($key);
            })->values()
            : $availableKeys;

        $summaryMap = $summary->keyBy('key');
        $seriesMap = [];
        foreach ($keysToRender as $key) {
            $node = $summaryMap->get($key);
            if (!$node) {
                continue;
            }

            $seriesMap[$key] = [
                'key' => $key,
                'id' => $node['id'],
                'type' => $node['type'],
                'name' => $node['name'],
                'total' => 0,
                'points' => [],
            ];

            foreach ($labels as $labelMeta) {
                $seriesMap[$key]['points'][$labelMeta['timestamp']] = [
                    'timestamp' => $labelMeta['timestamp'],
                    'label' => $labelMeta['label'],
                    'u' => 0,
                    'd' => 0,
                    'total' => 0,
                    'cost' => 0,
                ];
            }
        }

        foreach ($logs as $row) {
            $key = $this->buildNodeKey($row->node_type ?? null, $row->server_id);
            $recordAt = (int) $row->record_at;
            if (!isset($seriesMap[$key]['points'][$recordAt])) {
                continue;
            }

            $u = (int) $row->u;
            $d = (int) $row->d;
            $seriesMap[$key]['points'][$recordAt] = [
                'timestamp' => $recordAt,
                'label' => date($format, $recordAt),
                'u' => $u,
                'd' => $d,
                'total' => $u + $d,
                'cost' => (float) $row->cost,
            ];
        }

        $series = collect($seriesMap)->map(function ($item) {
            $points = collect($item['points'])->sortBy('timestamp')->values();
            $item['points'] = $points;
            $item['total'] = (int) $points->sum('total');
            return $item;
        })->values();

        if ($includeTotal) {
            $totalPoints = collect($labels)->map(function ($labelMeta) use ($series) {
                $u = 0;
                $d = 0;
                $cost = 0;
                foreach ($series as $item) {
                    $point = collect($item['points'])->firstWhere('timestamp', $labelMeta['timestamp']);
                    $u += (int) ($point['u'] ?? 0);
                    $d += (int) ($point['d'] ?? 0);
                    $cost += (float) ($point['cost'] ?? 0);
                }
                return [
                    'timestamp' => $labelMeta['timestamp'],
                    'label' => $labelMeta['label'],
                    'u' => $u,
                    'd' => $d,
                    'total' => $u + $d,
                    'cost' => $cost,
                ];
            })->values();

            $series->prepend([
                'key' => 'total',
                'id' => 0,
                'type' => 'all',
                'name' => '全部节点',
                'total' => (int) $totalPoints->sum('total'),
                'points' => $totalPoints,
            ]);
        }

        return [
            'labels' => array_map(function ($item) {
                return $item['label'];
            }, $labels),
            'series' => $series->values(),
        ];
    }

    private function buildNodeKey(?string $type, $id): string
    {
        return strtolower((string) $type) . ':' . (string) $id;
    }

    private function resolveNodeNames($rows)
    {
        $modelMap = [
            'shadowsocks' => \App\Models\ServerShadowsocks::class,
            'trojan' => \App\Models\ServerTrojan::class,
            'vmess' => \App\Models\ServerVmess::class,
            'v2ray' => \App\Models\ServerVmess::class,
            'vless' => \App\Models\ServerVless::class,
            'tuic' => \App\Models\ServerTuic::class,
            'hysteria' => \App\Models\ServerHysteria::class,
            'anytls' => \App\Models\ServerAnytls::class,
            'v2node' => \App\Models\ServerV2node::class,
        ];

        $nameMap = collect();
        $groupedRows = collect($rows)->groupBy(function ($row) {
            return strtolower((string) ($row->server_type ?? $row->node_type ?? ''));
        });

        foreach ($groupedRows as $type => $items) {
            $modelClass = $modelMap[$type] ?? null;
            if (!$modelClass) {
                continue;
            }

            $ids = $items->map(function ($item) {
                return (int) ($item->id ?? $item->server_id ?? 0);
            })->filter()->unique()->values();

            $servers = $modelClass::whereIn('id', $ids->all())->get(['id', 'name'])->keyBy('id');
            foreach ($items as $item) {
                $id = (int) ($item->id ?? $item->server_id ?? 0);
                $server = $servers->get($id);
                if ($server && !empty($server->name)) {
                    $nameMap->put($this->buildNodeKey($type, $id), $server->name);
                }
            }
        }

        return $nameMap;
    }

    private function resolveNodeProtocols($rows)
    {
        $protocolMap = collect();
        $groupedRows = collect($rows)->groupBy(function ($row) {
            return strtolower((string) ($row->server_type ?? $row->node_type ?? ''));
        });

        foreach ($groupedRows as $type => $items) {
            if ($type !== 'v2node') {
                foreach ($items as $item) {
                    $id = (int) ($item->id ?? $item->server_id ?? 0);
                    $protocolMap->put($this->buildNodeKey($type, $id), $type);
                }
                continue;
            }

            $ids = $items->map(function ($item) {
                return (int) ($item->id ?? $item->server_id ?? 0);
            })->filter()->unique()->values();

            $servers = \App\Models\ServerV2node::whereIn('id', $ids->all())->get(['id', 'protocol'])->keyBy('id');
            foreach ($items as $item) {
                $id = (int) ($item->id ?? $item->server_id ?? 0);
                $server = $servers->get($id);
                $protocol = strtolower((string) ($server->protocol ?? 'v2node'));
                $protocolMap->put($this->buildNodeKey($type, $id), $protocol ?: 'v2node');
            }
        }

        return $protocolMap;
    }
}
