<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $builder = \App\Models\ServerLog::select([
            'server_id',
            'method as node_type',
            'u',
            'd',
            'rate',
            'log_at as record_at'
        ])
            ->where('user_id', $request->user['id'])
            ->where('log_at', '>=', strtotime('-30 days'))
            ->orderBy('log_at', 'DESC');
        
        $logs = $builder->get();

        // 格式化计费: 消耗额度 = (u + d) * rate
        $logs->transform(function ($item) {
            $item->cost = ($item->u + $item->d) * $item->rate;
            return $item;
        });

        return response([
            'data' => $logs
        ]);
    }
}
