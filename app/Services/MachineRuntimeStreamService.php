<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

class MachineRuntimeStreamService
{
    public const SESSION_TTL_SECONDS = 300;
    public const RESULT_TTL_SECONDS = 600;
    public const MAX_CHUNK_BYTES = 8192;
    public const MAX_BUFFER_BYTES = 65536;

    private Repository $cache;

    public function __construct(Repository $cache)
    {
        $this->cache = $cache;
    }

    public function start(Machine $machine, string $service): array
    {
        $now = time();
        $service = $this->normalizeService($service);
        $activeKey = $this->activeKey((int) $machine->id);
        $previous = $this->cache->get($activeKey);
        if (is_array($previous) && !empty($previous['session_id'])) {
            $this->markStopped((int) $machine->id, (string) $previous['session_id'], 'replaced');
        }

        $session = [
            'session_id' => $now . '-' . Str::random(24),
            'machine_id' => (int) $machine->id,
            'machine_name' => (string) $machine->name,
            'service' => $service,
            'status' => 'waiting',
            'sequence' => 0,
            'buffer_bytes' => 0,
            'chunks' => [],
            'error' => '',
            'created_at' => $now,
            'updated_at' => $now,
            'last_activity_at' => 0,
            'expires_at' => $now + self::SESSION_TTL_SECONDS,
        ];

        $this->cache->put($activeKey, $this->agentConfig($session), self::SESSION_TTL_SECONDS);
        $this->cache->put($this->resultKey($session['session_id']), $session, self::RESULT_TTL_SECONDS);

        return $this->formatResult($session, 0);
    }

    public function activeForMachine(int $machineId): ?array
    {
        $session = $this->cache->get($this->activeKey($machineId));
        if (!is_array($session) || empty($session['session_id'])) {
            return null;
        }
        if ((int) ($session['expires_at'] ?? 0) <= time()) {
            $this->markStopped($machineId, (string) $session['session_id'], 'expired');
            $this->cache->forget($this->activeKey($machineId));
            return null;
        }

        return $this->agentConfig($session);
    }

    public function append(int $machineId, array $payload): bool
    {
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        $active = $this->activeForMachine($machineId);
        if (!$active || $sessionId === '' || !hash_equals((string) $active['session_id'], $sessionId)) {
            return false;
        }

        $resultKey = $this->resultKey($sessionId);
        $result = $this->cache->get($resultKey);
        if (!is_array($result) || (int) ($result['machine_id'] ?? 0) !== $machineId) {
            return false;
        }

        $now = time();
        $logs = $this->limitTail((string) ($payload['logs'] ?? ''), self::MAX_CHUNK_BYTES);
        $error = mb_substr(trim((string) ($payload['error'] ?? '')), 0, 255);
        $result['status'] = $error === '' ? 'streaming' : 'error';
        $result['error'] = $error;
        $result['updated_at'] = $now;
        $result['last_activity_at'] = (int) ($payload['collected_at'] ?? $now);

        if ($logs !== '') {
            $sequence = (int) ($result['sequence'] ?? 0) + 1;
            $chunks = is_array($result['chunks'] ?? null) ? $result['chunks'] : [];
            $chunks[] = [
                'sequence' => $sequence,
                'logs' => $logs,
                'collected_at' => $result['last_activity_at'],
            ];
            $bufferBytes = (int) ($result['buffer_bytes'] ?? 0) + strlen($logs);
            while ($bufferBytes > self::MAX_BUFFER_BYTES && count($chunks) > 1) {
                $removed = array_shift($chunks);
                $bufferBytes -= strlen((string) ($removed['logs'] ?? ''));
            }
            $result['sequence'] = $sequence;
            $result['chunks'] = $chunks;
            $result['buffer_bytes'] = max(0, $bufferBytes);
        }

        $this->cache->put($resultKey, $result, self::RESULT_TTL_SECONDS);
        return true;
    }

    public function result(int $machineId, string $sessionId, int $afterSequence = 0): ?array
    {
        $result = $this->cache->get($this->resultKey($sessionId));
        if (!is_array($result) || (int) ($result['machine_id'] ?? 0) !== $machineId) {
            return null;
        }

        $active = $this->cache->get($this->activeKey($machineId));
        $isActive = is_array($active)
            && !empty($active['session_id'])
            && hash_equals((string) $active['session_id'], $sessionId)
            && (int) ($active['expires_at'] ?? 0) > time();
        if (!$isActive && in_array((string) ($result['status'] ?? ''), ['waiting', 'streaming', 'error'], true)) {
            $result['status'] = (int) ($result['expires_at'] ?? 0) <= time() ? 'expired' : 'stopped';
            $result['updated_at'] = time();
            $this->cache->put($this->resultKey($sessionId), $result, self::RESULT_TTL_SECONDS);
        }

        return $this->formatResult($result, max(0, $afterSequence));
    }

    public function stop(int $machineId, string $sessionId): ?array
    {
        $result = $this->cache->get($this->resultKey($sessionId));
        if (!is_array($result) || (int) ($result['machine_id'] ?? 0) !== $machineId) {
            return null;
        }

        $active = $this->cache->get($this->activeKey($machineId));
        if (is_array($active)
            && !empty($active['session_id'])
            && hash_equals((string) $active['session_id'], $sessionId)) {
            $this->cache->forget($this->activeKey($machineId));
        }
        $this->markStopped($machineId, $sessionId, 'stopped');

        return $this->result($machineId, $sessionId, 0);
    }

    private function markStopped(int $machineId, string $sessionId, string $status): void
    {
        $key = $this->resultKey($sessionId);
        $result = $this->cache->get($key);
        if (!is_array($result) || (int) ($result['machine_id'] ?? 0) !== $machineId) {
            return;
        }
        $result['status'] = $status;
        $result['updated_at'] = time();
        $this->cache->put($key, $result, self::RESULT_TTL_SECONDS);
    }

    private function formatResult(array $result, int $afterSequence): array
    {
        $chunks = collect(is_array($result['chunks'] ?? null) ? $result['chunks'] : [])
            ->filter(fn (array $chunk) => (int) ($chunk['sequence'] ?? 0) > $afterSequence)
            ->values()
            ->all();

        return [
            'session_id' => (string) ($result['session_id'] ?? ''),
            'machine_id' => (int) ($result['machine_id'] ?? 0),
            'machine_name' => (string) ($result['machine_name'] ?? ''),
            'service' => (string) ($result['service'] ?? ''),
            'status' => (string) ($result['status'] ?? 'waiting'),
            'sequence' => (int) ($result['sequence'] ?? 0),
            'chunks' => $chunks,
            'error' => (string) ($result['error'] ?? ''),
            'created_at' => (int) ($result['created_at'] ?? 0),
            'updated_at' => (int) ($result['updated_at'] ?? 0),
            'last_activity_at' => (int) ($result['last_activity_at'] ?? 0),
            'expires_at' => (int) ($result['expires_at'] ?? 0),
        ];
    }

    private function agentConfig(array $session): array
    {
        return [
            'session_id' => (string) ($session['session_id'] ?? ''),
            'service' => (string) ($session['service'] ?? ''),
            'expires_at' => (int) ($session['expires_at'] ?? 0),
        ];
    }

    private function normalizeService(string $service): string
    {
        $service = strtolower(trim($service));
        return $service === 'gost' ? 'gost' : 'ravel';
    }

    private function limitTail(string $value, int $limit): string
    {
        $value = trim($value);
        if (strlen($value) <= $limit) {
            return $value;
        }
        return mb_strcut($value, strlen($value) - $limit, $limit, 'UTF-8');
    }

    private function activeKey(int $machineId): string
    {
        return 'v2node_probe_runtime_stream:' . $machineId;
    }

    private function resultKey(string $sessionId): string
    {
        return 'v2node_probe_runtime_stream_result:' . $sessionId;
    }
}
