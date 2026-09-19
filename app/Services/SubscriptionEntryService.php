<?php

namespace App\Services;

use App\Models\ServerMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscriptionEntryService
{
    public function apply(array $servers): array
    {
        if (!$servers || !Schema::hasColumn('v2_server_metadata', 'entry_host')) return $servers;
        $entries = ServerMetadata::whereNotNull('entry_host')->get()->keyBy(fn ($entry) => $entry->server_type . ':' . $entry->server_id);
        foreach ($servers as &$server) {
            $entry = $entries->get($server['type'] . ':' . $server['id']);
            if ($entry && filter_var($entry->entry_host, FILTER_VALIDATE_IP)) $server['host'] = $entry->entry_host;
        }
        unset($server);
        return $servers;
    }

    public function preview(int $machineId, ?string $host = null): array
    {
        $entries = ServerMetadata::where('entry_machine_id', $machineId)->get()->keyBy(fn ($entry) => $entry->server_type . ':' . $entry->server_id);
        $rows = [];
        foreach (app(ServerService::class)->getAllServers() as $server) {
            $key = $server['type'] . ':' . $server['id'];
            $entry = $entries->get($key);
            if (!$entry) continue;
            $rows[] = ['key' => $key, 'name' => $server['name'], 'port' => $server['port'], 'before' => $entry->entry_host ?: $server['host'], 'after' => $host,
                'metadata_id' => $entry->id, 'original_override' => $entry->entry_host];
        }
        return $rows;
    }

    public function bindConfigured(array $hostMachines, array $machineIds): array
    {
        $result = ['bound'=>[], 'unresolved'=>[]];
        DB::transaction(function () use ($hostMachines, $machineIds, &$result) {
            foreach (app(ServerService::class)->getAllServers() as $server) {
                $key=$server['type'].':'.$server['id'];
                $entry=ServerMetadata::where('server_type',$server['type'])->where('server_id',$server['id'])->lockForUpdate()->first();
                if ($entry && $entry->entry_machine_id) continue;
                $machine=(int)($server['relay_machine_id']??0);
                if (!$machine) {
                    $host=strtolower(trim((string)($entry?->entry_host ?: ($server['host']??'')), '[] '));
                    $matches=array_values(array_unique($hostMachines[$host]??[]));
                    if(count($matches)===1)$machine=(int)$matches[0];
                    elseif (!$matches && empty($server['parent_id'])) $machine=(int)($server['machine_id']??0);
                }
                if(!$machine || !in_array($machine,$machineIds,true)) { $result['unresolved'][]=$key; continue; }
                ServerMetadata::updateOrCreate(['server_type'=>$server['type'],'server_id'=>$server['id']],['entry_machine_id'=>$machine]);
                $result['bound'][]=['key'=>$key,'machine_id'=>$machine];
            }
        });
        return $result;
    }

    public function commit(int $machineId, string $host, array $preview): int
    {
        return DB::transaction(function () use ($machineId, $host, $preview) {
            $current = ServerMetadata::where('entry_machine_id', $machineId)->lockForUpdate()->get()->keyBy('id');
            foreach ($preview as $row) {
                $entry = $current->get($row['metadata_id']);
                abort_unless($entry && $entry->entry_host === $row['original_override'], 409, '入口绑定或地址已改变，请重新预览');
            }
            // Only metadata changes: no server model writes, node sync, or relay regeneration.
            foreach ($preview as $row) {
                $entry = $current->get($row['metadata_id']);
                $entry->entry_host = $host;
                $entry->save();
            }
            return count($preview);
        });
    }
}
