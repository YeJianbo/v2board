<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessingBatchService
{
    private const REGISTRY_KEY = 'v2board:processing_batches';

    public function move(string $sourceKey, string $kind, ?string $batchId = null): array
    {
        if (!in_array($kind, ['hash', 'zset'], true)) {
            throw new \InvalidArgumentException('Unsupported Redis batch type');
        }

        $redis = Redis::connection();
        if (!$redis->exists($sourceKey)) {
            return [null, []];
        }

        $batchId = $batchId ?: bin2hex(random_bytes(16));
        $processingKey = $sourceKey . ':processing:' . $batchId;
        $entry = [
            'batch_id' => $batchId,
            'source_key' => $sourceKey,
            'processing_key' => $processingKey,
            'kind' => $kind,
        ];
        $member = $this->encodeEntry($entry);
        $redis->sadd(self::REGISTRY_KEY, $member);

        try {
            $redis->rename($sourceKey, $processingKey);
        } catch (\Throwable $e) {
            $redis->srem(self::REGISTRY_KEY, $member);
            return [null, []];
        }

        $entry['member'] = $member;
        $data = $kind === 'hash'
            ? ($redis->hgetall($processingKey) ?: [])
            : ($redis->zrange($processingKey, 0, -1, true) ?: []);
        return [$entry, $data];
    }

    public function record(string $batchId, string $stream): void
    {
        DB::table('v2_processing_batch')->insert([
            'batch_id' => $batchId,
            'stream' => substr($stream, 0, 96),
            'created_at' => time(),
        ]);
    }

    public function complete(?array $entry): void
    {
        if (!$entry) {
            return;
        }
        $redis = Redis::connection();
        $redis->del($entry['processing_key']);
        $redis->srem(self::REGISTRY_KEY, $entry['member'] ?? $this->encodeEntry($entry));
    }

    public function restore(?array $entry): void
    {
        if (!$entry) {
            return;
        }
        $redis = Redis::connection();
        $processingKey = (string)$entry['processing_key'];
        if ($redis->exists($processingKey)) {
            $this->mergeAtomically(
                (string)$entry['source_key'],
                $processingKey,
                (string)$entry['kind']
            );
        }
        $redis->srem(self::REGISTRY_KEY, $entry['member'] ?? $this->encodeEntry($entry));
    }

    public function recover(): void
    {
        $redis = Redis::connection();
        $members = $redis->smembers(self::REGISTRY_KEY) ?: [];
        if (!$members) {
            return;
        }

        $entries = [];
        foreach ($members as $member) {
            $entry = json_decode((string)$member, true);
            if (!is_array($entry) || empty($entry['batch_id']) || empty($entry['processing_key'])) {
                $redis->srem(self::REGISTRY_KEY, $member);
                continue;
            }
            $entry['member'] = (string)$member;
            $entries[] = $entry;
        }

        try {
            $completed = DB::table('v2_processing_batch')
                ->whereIn('batch_id', array_column($entries, 'batch_id'))
                ->pluck('batch_id')
                ->all();
            $completed = array_fill_keys($completed, true);
        } catch (\Throwable $e) {
            Log::error('计费批次恢复检查失败', ['exception' => $e]);
            return;
        }

        foreach ($entries as $entry) {
            try {
                if (isset($completed[$entry['batch_id']])) {
                    $this->complete($entry);
                } else {
                    $this->restore($entry);
                }
            } catch (\Throwable $e) {
                Log::error('计费批次恢复失败', [
                    'batch_id' => $entry['batch_id'],
                    'exception' => $e,
                ]);
            }
        }
    }

    private function mergeAtomically(string $sourceKey, string $processingKey, string $kind): void
    {
        $redis = Redis::connection();
        if ($kind === 'hash') {
            $script = <<<'LUA'
local sourceTtl = redis.call('PTTL', KEYS[1])
local processingTtl = redis.call('PTTL', KEYS[2])
local values = redis.call('HGETALL', KEYS[2])
for i = 1, #values, 2 do
    redis.call('HINCRBY', KEYS[1], values[i], values[i + 1])
end
redis.call('DEL', KEYS[2])
local ttl = math.max(sourceTtl, processingTtl)
if ttl > 0 then redis.call('PEXPIRE', KEYS[1], ttl) end
return #values / 2
LUA;
        } else {
            $script = <<<'LUA'
local sourceTtl = redis.call('PTTL', KEYS[1])
local processingTtl = redis.call('PTTL', KEYS[2])
local values = redis.call('ZRANGE', KEYS[2], 0, -1, 'WITHSCORES')
for i = 1, #values, 2 do
    redis.call('ZINCRBY', KEYS[1], values[i + 1], values[i])
end
redis.call('DEL', KEYS[2])
local ttl = math.max(sourceTtl, processingTtl)
if ttl > 0 then redis.call('PEXPIRE', KEYS[1], ttl) end
return #values / 2
LUA;
        }
        $redis->eval($script, 2, $sourceKey, $processingKey);
    }

    private function encodeEntry(array $entry): string
    {
        unset($entry['member']);
        return json_encode($entry, JSON_UNESCAPED_SLASHES);
    }
}
