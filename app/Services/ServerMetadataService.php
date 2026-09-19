<?php

namespace App\Services;

use App\Models\ServerMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ServerMetadataService
{
    private const FIELDS = ['country_code', 'country_name', 'display_group', 'entry_machine_id', 'entry_host'];

    public function validateRequest(Request $request): ?array
    {
        $input = $request->all();
        $hasMetadata = false;
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $hasMetadata = true;
                break;
            }
        }

        if (!$hasMetadata) {
            return null;
        }

        $validated = Validator::make($request->only(self::FIELDS), [
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'country_name' => ['nullable', 'string', 'max:64'],
            'display_group' => ['nullable', 'string', 'max:64'],
            'entry_machine_id' => ['nullable', 'integer', 'exists:v2_machine,id'],
            'entry_host' => ['nullable', 'ip'],
        ], [
            'country_code.size' => '国家代码必须为两位字母',
            'country_code.regex' => '国家代码必须为两位字母',
            'country_name.max' => '国家或地区名称最多 64 个字符',
            'display_group.max' => '展示分组最多 64 个字符',
        ])->validate();

        $metadata = [
            'country_code' => ($countryCode = strtoupper(trim((string) ($validated['country_code'] ?? '')))) !== ''
                ? $countryCode
                : null,
            'country_name' => ($countryName = trim((string) ($validated['country_name'] ?? ''))) !== ''
                ? $countryName
                : null,
            'display_group' => ($displayGroup = trim((string) ($validated['display_group'] ?? ''))) !== ''
                ? $displayGroup
                : null,
        ];
        foreach (['entry_machine_id', 'entry_host'] as $field) {
            if (array_key_exists($field, $input)) $metadata[$field] = $validated[$field] ?? null;
        }
        return $metadata;
    }

    public function save(string $serverType, int $serverId, ?array $metadata): void
    {
        if ($metadata === null || $serverId <= 0 || !Schema::hasTable('v2_server_metadata')) {
            return;
        }

        $serverType = strtolower(trim($serverType));
        ServerMetadata::updateOrCreate(
            ['server_type' => $serverType, 'server_id' => $serverId],
            $metadata
        );
    }

    public function delete(string $serverType, int $serverId): void
    {
        if ($serverId <= 0 || !Schema::hasTable('v2_server_metadata')) {
            return;
        }

        ServerMetadata::query()
            ->where('server_type', strtolower(trim($serverType)))
            ->where('server_id', $serverId)
            ->delete();
    }

    public function copy(string $serverType, int $sourceServerId, int $targetServerId): void
    {
        if ($sourceServerId <= 0 || $targetServerId <= 0 || !Schema::hasTable('v2_server_metadata')) {
            return;
        }

        $serverType = strtolower(trim($serverType));
        $source = ServerMetadata::query()
            ->where('server_type', $serverType)
            ->where('server_id', $sourceServerId)
            ->first();

        if (!$source) {
            return;
        }

        ServerMetadata::updateOrCreate(
            ['server_type' => $serverType, 'server_id' => $targetServerId],
            $source->only(self::FIELDS)
        );
    }

    public function decorateServers(array $servers): array
    {
        if (!$servers || !Schema::hasTable('v2_server_metadata')) {
            return $servers;
        }

        $metadataByKey = ServerMetadata::query()
            ->get()
            ->keyBy(function (ServerMetadata $metadata) {
                return strtolower((string) $metadata->server_type) . ':' . (int) $metadata->server_id;
            });

        return array_map(function (array $server) use ($metadataByKey) {
            $key = strtolower((string) ($server['type'] ?? '')) . ':' . (int) ($server['id'] ?? 0);
            $metadata = $metadataByKey->get($key);
            $server['country_code'] = $metadata ? (string) ($metadata->country_code ?: '') : '';
            $server['country_name'] = $metadata ? (string) ($metadata->country_name ?: '') : '';
            $server['display_group'] = $metadata ? (string) ($metadata->display_group ?: '') : '';
            $server['entry_machine_id'] = $metadata ? $metadata->entry_machine_id : null;
            $server['entry_host'] = $metadata ? $metadata->entry_host : null;

            return $server;
        }, $servers);
    }
}
