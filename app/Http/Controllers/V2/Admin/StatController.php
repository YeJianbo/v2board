<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\StatUserServer;
use App\Models\StatUserServerHour;
use App\Models\StatUserServerMinute;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StatController extends Controller
{
    private $service;
    public function __construct(StatisticalService $service)
    {
        $this->service = $service;
    }
    private function getTotalNodesCount()
    {
        return \App\Models\ServerShadowsocks::count()
            + \App\Models\ServerVmess::count()
            + \App\Models\ServerTrojan::count()
            + \App\Models\ServerVless::count()
            + \App\Models\ServerTuic::count()
            + \App\Models\ServerHysteria::count()
            + \App\Models\ServerAnytls::count()
            + \App\Models\ServerV2node::count();
    }

    private function getOnlineUserSummary(): array
    {
        $onlineUsers = 0;
        $onlineDevices = 0;

        foreach (User::query()->pluck('id') as $userId) {
            $state = Cache::get('ALIVE_IP_USER_' . $userId);
            if (!is_array($state)) {
                continue;
            }

            $count = (int) ($state['alive_ip'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $onlineUsers++;
            $onlineDevices += $count;
        }

        if ($onlineUsers === 0) {
            $fallback = User::where('t', '>=', time() - 600)->count();
            return [
                'users' => $fallback,
                'devices' => $fallback,
            ];
        }

        return [
            'users' => $onlineUsers,
            'devices' => $onlineDevices,
        ];
    }



    public function getOverride(Request $request)
    {
        $onlineNodes = $this->getTotalNodesCount();
        $onlineSummary = $this->getOnlineUserSummary();
        $onlineDevices = $onlineSummary['devices'];
        $onlineUsers = $onlineSummary['users'];

        // 获取今日流量统计
        $todayStart = strtotime('today');
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthStart = strtotime(date('Y-m-1'));
        $monthTraffic = StatServer::where('record_at', '>=', $monthStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        return [
            'data' => [
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
                // 新增统计数据
                'online_nodes' => $onlineNodes,
                'online_devices' => $onlineDevices,
                'online_users' => $onlineUsers,
                'today_traffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'month_traffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'total_traffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get order statistics with filtering and pagination
     *
     * @param Request $request
     * @return array
     */
    public function getOrder(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|in:paid_total,paid_count,commission_total,commission_count',
        ]);

        $query = Stat::where('record_type', 'd');

        // Apply date filters
        if ($request->input('start_date')) {
            $query->where('record_at', '>=', strtotime($request->input('start_date')));
        }
        if ($request->input('end_date')) {
            $query->where('record_at', '<=', strtotime($request->input('end_date') . ' 23:59:59'));
        }

        $statistics = $query->orderBy('record_at', 'DESC')
            ->get();

        $summary = [
            'paid_total' => 0,
            'paid_count' => 0,
            'commission_total' => 0,
            'commission_count' => 0,
            'start_date' => $request->input('start_date', date('Y-m-d', $statistics->last()?->record_at)),
            'end_date' => $request->input('end_date', date('Y-m-d', $statistics->first()?->record_at)),
            'avg_paid_amount' => 0,
            'avg_commission_amount' => 0
        ];

        $dailyStats = [];
        foreach ($statistics as $statistic) {
            $date = date('Y-m-d', $statistic['record_at']);

            // Update summary
            $summary['paid_total'] += $statistic['paid_total'];
            $summary['paid_count'] += $statistic['paid_count'];
            $summary['commission_total'] += $statistic['commission_total'];
            $summary['commission_count'] += $statistic['commission_count'];

            // Calculate daily stats
            $dailyData = [
                'date' => $date,
                'paid_total' => $statistic['paid_total'],
                'paid_count' => $statistic['paid_count'],
                'commission_total' => $statistic['commission_total'],
                'commission_count' => $statistic['commission_count'],
                'avg_order_amount' => $statistic['paid_count'] > 0 ? round($statistic['paid_total'] / $statistic['paid_count'], 2) : 0,
                'avg_commission_amount' => $statistic['commission_count'] > 0 ? round($statistic['commission_total'] / $statistic['commission_count'], 2) : 0
            ];

            if ($request->input('type')) {
                $dailyStats[] = [
                    'date' => $date,
                    'value' => $statistic[$request->input('type')],
                    'type' => $this->getTypeLabel($request->input('type'))
                ];
            } else {
                $dailyStats[] = $dailyData;
            }
        }

        // Calculate averages for summary
        if ($summary['paid_count'] > 0) {
            $summary['avg_paid_amount'] = round($summary['paid_total'] / $summary['paid_count'], 2);
        }
        if ($summary['commission_count'] > 0) {
            $summary['avg_commission_amount'] = round($summary['commission_total'] / $summary['commission_count'], 2);
        }

        // Add percentage calculations to summary
        $summary['commission_rate'] = $summary['paid_total'] > 0
            ? round(($summary['commission_total'] / $summary['paid_total']) * 100, 2)
            : 0;

        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'list' => array_reverse($dailyStats),
                'summary' => $summary,
            ]
        ];
    }

    /**
     * Get human readable label for statistic type
     *
     * @param string $type
     * @return string
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type
        };
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $data = $this->service->getServerRank();
        return $this->success(data: $data);
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $data = $this->service->getServerRank('yesterday');
        return $this->success($data);
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        $pageSize = $request->input('pageSize', 10);
        $recordsQuery = StatUser::where('user_id', $request->input('user_id'));

        if ($request->input('start_time')) {
            $recordsQuery->where('record_at', '>=', (int) $request->input('start_time'));
        }
        if ($request->input('end_time')) {
            $recordsQuery->where('record_at', '<=', (int) $request->input('end_time'));
        }

        $records = $recordsQuery
            ->orderBy('record_at', 'DESC')
            ->paginate($pageSize);

        $data = $records->items();
        return [
            'data' => $data,
            'total' => $records->total(),
        ];
    }

    public function getUserNodeTraffic(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        $userId = (int) $request->input('user_id');
        $statsQuery = StatUserServer::where('user_id', $userId);

        if ($request->input('start_time')) {
            $statsQuery->where('record_at', '>=', (int) $request->input('start_time'));
        }
        if ($request->input('end_time')) {
            $statsQuery->where('record_at', '<=', (int) $request->input('end_time'));
        }

        $range = (clone $statsQuery)
            ->selectRaw('MIN(record_at) as start_at, MAX(record_at) as end_at, SUM(u + d) as user_total')
            ->first();

        if (!$range || !$range->start_at || !$range->end_at) {
            return [
                'data' => [],
                'meta' => [
                    'start_at' => null,
                    'end_at' => null,
                    'user_total' => 0,
                    'note' => '节点维度流量从新版部署后开始统计，历史数据无法拆分到节点。',
                ],
            ];
        }

        $rows = (clone $statsQuery)
            ->selectRaw('server_id as id, server_type, MAX(server_rate) as rate, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->get();

        $names = $this->resolveNodeRankNames($rows);
        $protocols = $this->resolveNodeRankProtocols($rows);

        $data = $rows->map(function ($row) use ($names, $protocols) {
            $key = $this->buildNodeRankKey($row->server_type ?? null, $row->id);
            return [
                'id' => (int) $row->id,
                'type' => (string) ($protocols[$key] ?? $row->server_type),
                'name' => $names[$key] ?? "Node {$row->id}",
                'rate' => (string) ($row->rate ?? '1.00'),
                'u' => (int) $row->u,
                'd' => (int) $row->d,
                'total' => (int) $row->total,
            ];
        })->values();

        return [
            'data' => $data,
            'meta' => [
                'start_at' => (int) $range->start_at,
                'end_at' => (int) $range->end_at,
                'user_total' => (int) $range->user_total,
                'note' => null,
            ],
        ];
    }

    public function getUserNodeTrafficSeries(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'granularity' => 'nullable|in:minute,hour,day',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'node_keys' => 'nullable|array',
            'node_keys.*' => 'nullable|string|max:64',
            'include_total' => 'nullable|boolean',
        ]);

        $userId = (int) $request->input('user_id');
        $granularity = (string) ($request->input('granularity') ?: 'day');
        $includeTotal = $request->boolean('include_total', true);
        $nodeKeys = collect($request->input('node_keys', []))
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->filter()
            ->values();

        if ($granularity === 'minute') {
            $model = StatUserServerMinute::query();
            $step = 60;
            $format = 'H:i';
            $endAt = strtotime(date('Y-m-d H:i:00', (int) $request->input('end_time', time())));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d H:i:00', (int) $request->input('start_time')))
                : $endAt - 59 * $step;
            $maxRange = 24 * 3600;
            $note = '分钟曲线从当前版本部署后开始统计。';
        } elseif ($granularity === 'hour') {
            $model = StatUserServerHour::query();
            $step = 3600;
            $format = 'm-d H:00';
            $endAt = strtotime(date('Y-m-d H:00:00', (int) $request->input('end_time', time())));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d H:00:00', (int) $request->input('start_time')))
                : $endAt - 47 * $step;
            $maxRange = 31 * 24 * 3600;
            $note = '小时曲线从新版部署后开始统计。';
        } else {
            $model = StatUserServer::query();
            $step = 86400;
            $format = 'm-d';
            $endAt = strtotime(date('Y-m-d', (int) $request->input('end_time', time())));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d', (int) $request->input('start_time')))
                : $endAt - 29 * $step;
            $maxRange = 366 * 86400;
            $note = null;
        }

        if ($startAt > $endAt) {
            [$startAt, $endAt] = [$endAt, $startAt];
        }
        if (($endAt - $startAt) > $maxRange) {
            $startAt = $endAt - $maxRange;
        }

        $baseQuery = $model
            ->where('user_id', $userId)
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<=', $endAt);

        $requestedNodePairs = $nodeKeys
            ->map(function ($nodeKey) {
                [$type, $id] = array_pad(explode(':', strtolower((string) $nodeKey), 2), 2, null);
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

        $rows = $baseQuery
            ->selectRaw('server_id, server_type, record_at, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->groupBy('server_id', 'server_type', 'record_at')
            ->orderBy('record_at')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'data' => [
                    'labels' => [],
                    'series' => [],
                ],
                'meta' => [
                    'granularity' => $granularity,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'note' => $note ?: '暂无节点维度曲线数据。',
                ],
            ];
        }

        $availableKeys = $rows
            ->map(function ($row) {
                return $this->buildNodeRankKey($row->server_type ?? null, $row->server_id);
            })
            ->unique()
            ->values();

        $keysToRender = $requestedNodePairs->isNotEmpty()
            ? $requestedNodePairs
                ->pluck('key')
                ->filter(function ($key) use ($availableKeys) {
                    return $availableKeys->contains($key);
                })
                ->values()
            : $availableKeys;

        $renderRows = $rows->filter(function ($row) use ($keysToRender) {
            return $keysToRender->contains($this->buildNodeRankKey($row->server_type ?? null, $row->server_id));
        })->values();

        $names = $this->resolveNodeRankNames($renderRows);
        $protocols = $this->resolveNodeRankProtocols($renderRows);
        $labels = [];
        $buckets = [];

        for ($cursor = $startAt; $cursor <= $endAt; $cursor += $step) {
            $labels[] = [
                'timestamp' => $cursor,
                'label' => date($format, $cursor),
            ];
            $buckets[$cursor] = [
                'timestamp' => $cursor,
                'label' => date($format, $cursor),
            ];
        }

        $seriesMap = [];
        foreach ($keysToRender as $key) {
            $seriesMap[$key] = [
                'key' => $key,
                'id' => (int) explode(':', $key, 2)[1],
                'type' => (string) ($protocols[$key] ?? explode(':', $key, 2)[0]),
                'name' => (string) ($names[$key] ?? ('Node ' . explode(':', $key, 2)[1])),
                'points' => [],
            ];
        }

        foreach ($labels as $labelMeta) {
            foreach ($seriesMap as $key => $series) {
                $seriesMap[$key]['points'][$labelMeta['timestamp']] = [
                    'timestamp' => $labelMeta['timestamp'],
                    'label' => $labelMeta['label'],
                    'u' => 0,
                    'd' => 0,
                    'total' => 0,
                ];
            }
        }

        foreach ($renderRows as $row) {
            $key = $this->buildNodeRankKey($row->server_type ?? null, $row->server_id);
            $recordAt = (int) $row->record_at;
            if (!isset($seriesMap[$key]['points'][$recordAt])) {
                continue;
            }

            $u = (int) ($row->u ?? 0);
            $d = (int) ($row->d ?? 0);
            $seriesMap[$key]['points'][$recordAt] = [
                'timestamp' => $recordAt,
                'label' => $buckets[$recordAt]['label'] ?? date($format, $recordAt),
                'u' => $u,
                'd' => $d,
                'total' => $u + $d,
            ];
        }

        $series = collect($seriesMap)->map(function ($seriesItem) {
            $points = collect($seriesItem['points'])->sortBy('timestamp')->values();
            $total = (int) $points->sum('total');

            return [
                'key' => $seriesItem['key'],
                'id' => $seriesItem['id'],
                'type' => $seriesItem['type'],
                'name' => $seriesItem['name'],
                'total' => $total,
                'points' => $points,
            ];
        })->values();

        if ($includeTotal) {
            $totalPoints = collect($labels)->map(function ($labelMeta) use ($series) {
                $u = 0;
                $d = 0;
                foreach ($series as $item) {
                    $point = collect($item['points'])->firstWhere('timestamp', $labelMeta['timestamp']);
                    $u += (int) ($point['u'] ?? 0);
                    $d += (int) ($point['d'] ?? 0);
                }

                return [
                    'timestamp' => $labelMeta['timestamp'],
                    'label' => $labelMeta['label'],
                    'u' => $u,
                    'd' => $d,
                    'total' => $u + $d,
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
            'data' => [
                'labels' => array_map(function ($item) {
                    return $item['label'];
                }, $labels),
                'series' => $series->values(),
            ],
            'meta' => [
                'granularity' => $granularity,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'note' => $note,
            ],
        ];
    }

    public function getNodeUserTraffic(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer',
            'server_type' => 'required|string|max:32',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $serverId = (int) $request->input('server_id');
        $serverType = strtolower((string) $request->input('server_type'));
        $limit = min(max((int) $request->input('limit', 20), 1), 100);
        $statsQuery = StatUserServer::where('server_id', $serverId)
            ->where('server_type', $serverType);

        if ($request->input('start_time')) {
            $statsQuery->where('record_at', '>=', (int) $request->input('start_time'));
        }
        if ($request->input('end_time')) {
            $statsQuery->where('record_at', '<=', (int) $request->input('end_time'));
        }

        $range = (clone $statsQuery)
            ->selectRaw('MIN(record_at) as start_at, MAX(record_at) as end_at, SUM(u + d) as node_total')
            ->first();

        if (!$range || !$range->start_at || !$range->end_at) {
            return [
                'data' => [],
                'meta' => [
                    'start_at' => null,
                    'end_at' => null,
                    'node_total' => 0,
                    'note' => '节点用户排行从新版部署后开始统计，历史数据无法拆分到用户。',
                ],
            ];
        }

        $rows = (clone $statsQuery)
            ->selectRaw('user_id, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->all())
            ->get(['id', 'email'])
            ->keyBy('id');

        $data = $rows->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);
            return [
                'user_id' => (int) $row->user_id,
                'email' => $user ? $user->email : "User {$row->user_id}",
                'u' => (int) $row->u,
                'd' => (int) $row->d,
                'total' => (int) $row->total,
            ];
        })->values();

        return [
            'data' => $data,
            'meta' => [
                'start_at' => (int) $range->start_at,
                'end_at' => (int) $range->end_at,
                'node_total' => (int) $range->node_total,
                'note' => null,
            ],
        ];
    }

    public function getNodeTrafficSeries(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer',
            'server_type' => 'required|string|max:32',
            'granularity' => 'nullable|in:hour,day',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        $serverId = (int) $request->input('server_id');
        $serverType = strtolower((string) $request->input('server_type'));
        $granularity = $request->input('granularity') === 'hour' ? 'hour' : 'day';
        $endInput = (int) $request->input('end_time', time());

        if ($granularity === 'hour') {
            $model = StatUserServerHour::query();
            $step = 3600;
            $format = 'm-d H:00';
            $endAt = strtotime(date('Y-m-d H:00:00', $endInput));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d H:00:00', (int) $request->input('start_time')))
                : $endAt - 23 * $step;
            $maxRange = 14 * 24 * $step;
        } else {
            $model = StatUserServer::query();
            $step = 86400;
            $format = 'm-d';
            $endAt = strtotime(date('Y-m-d', $endInput));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d', (int) $request->input('start_time')))
                : $endAt - 29 * $step;
            $maxRange = 366 * $step;
        }

        if ($startAt > $endAt) {
            [$startAt, $endAt] = [$endAt, $startAt];
        }
        if (($endAt - $startAt) > $maxRange) {
            $startAt = $endAt - $maxRange;
        }

        $rows = $model
            ->where('server_id', $serverId)
            ->where('server_type', $serverType)
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<=', $endAt)
            ->selectRaw('record_at, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->groupBy('record_at')
            ->orderBy('record_at')
            ->get()
            ->keyBy('record_at');

        $points = [];
        for ($cursor = $startAt; $cursor <= $endAt; $cursor += $step) {
            $row = $rows->get($cursor);
            $u = (int) ($row->u ?? 0);
            $d = (int) ($row->d ?? 0);
            $points[] = [
                'timestamp' => $cursor,
                'label' => date($format, $cursor),
                'u' => $u,
                'd' => $d,
                'total' => $u + $d,
            ];
        }

        return [
            'data' => $points,
            'meta' => [
                'granularity' => $granularity,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'total' => array_sum(array_column($points, 'total')),
                'note' => $granularity === 'hour'
                    ? '小时曲线从新版部署后开始统计，部署前无法按小时拆分。'
                    : null,
            ],
        ];
    }

    public function getStatRecord(Request $request)
    {
        return [
            'data' => $this->service->getStatRecord($request->input('type'))
        ];
    }

    /**
     * Get comprehensive statistics data including income, users, and growth rates
     */
    public function getStats()
    {
        $currentMonthStart = strtotime(date('Y-m-01'));
        $lastMonthStart = strtotime('-1 month', $currentMonthStart);
        $twoMonthsAgoStart = strtotime('-2 month', $currentMonthStart);

        // Today's start timestamp
        $todayStart = strtotime('today');
        $yesterdayStart = strtotime('-1 day', $todayStart);

        // 获取在线节点数
        $onlineNodes = $this->getTotalNodesCount();

        $onlineSummary = $this->getOnlineUserSummary();
        $onlineDevices = $onlineSummary['devices'];
        $onlineUsers = $onlineSummary['users'];

        // 获取今日流量统计
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthTraffic = StatServer::where('record_at', '>=', $currentMonthStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // Today's income
        $todayIncome = Order::where('created_at', '>=', $todayStart)
            ->where('created_at', '<', time())
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Yesterday's income for day growth calculation
        $yesterdayIncome = Order::where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<', $todayStart)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Current month income
        $currentMonthIncome = Order::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', time())
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Last month income
        $lastMonthIncome = Order::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Last month commission payout
        $lastMonthCommissionPayout = CommissionLog::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->sum('get_amount');

        // Current month commission payout
        $currentMonthCommissionPayout = CommissionLog::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', time())
            ->sum('get_amount');

        // Current month new users
        $currentMonthNewUsers = User::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', time())
            ->count();

        // Total users
        $totalUsers = User::count();

        // Active users (users with valid subscription)
        $activeUsers = User::where(function ($query) {
            $query->where('expired_at', '>=', time())
                ->orWhere('expired_at', NULL);
        })->count();

        // Previous month income for growth calculation
        $twoMonthsAgoIncome = Order::where('created_at', '>=', $twoMonthsAgoStart)
            ->where('created_at', '<', $lastMonthStart)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Previous month commission for growth calculation
        $twoMonthsAgoCommission = CommissionLog::where('created_at', '>=', $twoMonthsAgoStart)
            ->where('created_at', '<', $lastMonthStart)
            ->sum('get_amount');

        // Previous month users for growth calculation
        $lastMonthNewUsers = User::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->count();

        // Calculate growth rates
        $monthIncomeGrowth = $lastMonthIncome > 0 ? round(($currentMonthIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : 0;
        $lastMonthIncomeGrowth = $twoMonthsAgoIncome > 0 ? round(($lastMonthIncome - $twoMonthsAgoIncome) / $twoMonthsAgoIncome * 100, 1) : 0;
        $commissionGrowth = $twoMonthsAgoCommission > 0 ? round(($lastMonthCommissionPayout - $twoMonthsAgoCommission) / $twoMonthsAgoCommission * 100, 1) : 0;
        $userGrowth = $lastMonthNewUsers > 0 ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1) : 0;
        $dayIncomeGrowth = $yesterdayIncome > 0 ? round(($todayIncome - $yesterdayIncome) / $yesterdayIncome * 100, 1) : 0;

        // 获取待处理工单和佣金数据
        $ticketPendingTotal = Ticket::where('status', 0)->count();
        $commissionPendingTotal = Order::where('commission_status', 0)
            ->where('invite_user_id', '!=', NULL)
            ->whereIn('status', [3])
            ->where('commission_balance', '>', 0)
            ->count();

        return [
            'data' => [
                // 收入相关
                'todayIncome' => $todayIncome,
                'dayIncomeGrowth' => $dayIncomeGrowth,
                'currentMonthIncome' => $currentMonthIncome,
                'lastMonthIncome' => $lastMonthIncome,
                'monthIncomeGrowth' => $monthIncomeGrowth,
                'lastMonthIncomeGrowth' => $lastMonthIncomeGrowth,

                // 佣金相关
                'currentMonthCommissionPayout' => $currentMonthCommissionPayout,
                'lastMonthCommissionPayout' => $lastMonthCommissionPayout,
                'commissionGrowth' => $commissionGrowth,
                'commissionPendingTotal' => $commissionPendingTotal,

                // 用户相关
                'currentMonthNewUsers' => $currentMonthNewUsers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'userGrowth' => $userGrowth,
                'onlineUsers' => $onlineUsers,
                'onlineDevices' => $onlineDevices,

                // 工单相关
                'ticketPendingTotal' => $ticketPendingTotal,

                // 节点相关
                'onlineNodes' => $onlineNodes,

                // 流量统计
                'todayTraffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'monthTraffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'totalTraffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999'
        ]);

        $type = $request->input('type');
        $startDate = $request->input('start_time', strtotime('-7 days'));
        $endDate = $request->input('end_time', time());
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;

        if ($type === 'node') {
            // Get node traffic data
            $currentData = StatServer::selectRaw('server_id as id, server_type, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('server_id', 'server_type')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatServer::selectRaw('server_id as id, server_type, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->whereIn('server_type', $currentData->pluck('server_type')->filter()->unique()->values())
                ->groupBy('server_id', 'server_type')
                ->get()
                ->keyBy(function ($item) {
                    return $this->buildNodeRankKey($item->server_type, $item->id);
                });

        } else {
            // Get user traffic data
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        $ids = $currentData->pluck('id');
        $names = $type === 'node'
            ? $this->resolveNodeRankNames($currentData)
            : User::whereIn('id', $ids)->pluck('email', 'id');
        $protocols = $type === 'node'
            ? $this->resolveNodeRankProtocols($currentData)
            : collect();

        foreach ($currentData as $data) {
            $previousKey = $type === 'node'
                ? $this->buildNodeRankKey($data->server_type, $data->id)
                : $data->id;
            $previousValue = isset($previousData[$previousKey]) ? $previousData[$previousKey]->value : 0;
            $change = $previousValue > 0 ? round(($data->value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $data->id,
                'type' => $type === 'node' ? ($protocols[$this->buildNodeRankKey($data->server_type ?? null, $data->id)] ?? strtolower((string) ($data->server_type ?? ''))) : '',
                'name' => $names[$this->buildNodeRankKey($data->server_type ?? null, $data->id)] ?? $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }

    private function buildNodeRankKey(?string $type, $id): string
    {
        return strtolower((string) $type) . ':' . (string) $id;
    }

    private function resolveNodeRankNames($rows)
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
            return strtolower((string) $row->server_type);
        });

        foreach ($groupedRows as $type => $items) {
            $modelClass = $modelMap[$type] ?? null;
            if (!$modelClass) {
                continue;
            }

            $servers = $modelClass::whereIn('id', $items->pluck('id')->all())
                ->get(['id', 'name'])
                ->keyBy('id');

            foreach ($items as $item) {
                $server = $servers->get($item->id);
                if ($server && !empty($server->name)) {
                    $nameMap->put($this->buildNodeRankKey($type, $item->id), $server->name);
                }
            }
        }

        return $nameMap;
    }

    private function resolveNodeRankProtocols($rows)
    {
        $protocolMap = collect();
        $groupedRows = collect($rows)->groupBy(function ($row) {
            return strtolower((string) $row->server_type);
        });

        foreach ($groupedRows as $type => $items) {
            if ($type !== 'v2node') {
                foreach ($items as $item) {
                    $protocolMap->put($this->buildNodeRankKey($type, $item->id), $type);
                }

                continue;
            }

            $servers = \App\Models\ServerV2node::whereIn('id', $items->pluck('id')->all())
                ->get(['id', 'protocol'])
                ->keyBy('id');

            foreach ($items as $item) {
                $server = $servers->get($item->id);
                $protocol = strtolower((string) ($server->protocol ?? 'v2node'));
                $protocolMap->put($this->buildNodeRankKey($type, $item->id), $protocol ?: 'v2node');
            }
        }

        return $protocolMap;
    }
}
