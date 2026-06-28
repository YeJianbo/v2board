<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        try{
            if(isset($record['context']['exception']) && is_object($record['context']['exception'])){
                $record['context']['exception'] = (array)$record['context']['exception'];
            }
            $request = null;
            if (app()->bound('request')) {
                try {
                    $request = app('request');
                } catch (\Throwable $e) {
                    $request = null;
                }
            }
            $record['request_data'] = $request ? ($request->all() ?? []) : [];
            $datetime = $record['datetime'] ?? null;
            $timestamp = $datetime instanceof \DateTimeInterface
                ? $datetime->getTimestamp()
                : strtotime((string)$datetime);
            if (!$timestamp) {
                $timestamp = time();
            }
            $log = [
                'title' => $record['message'],
                'level' => $record['level_name'],
                'host' => $record['request_host'] ?? ($request ? $request->getSchemeAndHttpHost() : ''),
                'uri' => $record['request_uri'] ?? ($request ? $request->getRequestUri() : 'console'),
                'method' => $record['request_method'] ?? ($request ? $request->getMethod() : 'CLI'),
                'ip' => $request ? $request->getClientIp() : '',
                'data' => json_encode($record['request_data']) ,
                'context' => isset($record['context']) ? json_encode($record['context']) : '',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            LogModel::insert(
                $log
            );
        }catch (\Throwable $e){
            Log::channel('daily')->error($e->getMessage().$e->getFile().$e->getTraceAsString());
        }
    }
}
