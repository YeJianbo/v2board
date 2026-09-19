<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ModelRelayService
{
    public const PROTOCOLS = ['openai', 'responses', 'anthropic', 'gemini', 'responses_ws'];

    public function protocols(object $upstream): array
    {
        return json_decode($upstream->protocols ?? 'null', true) ?? ['openai'];
    }
    public function periodStart(string $period): string
    {
        $now = Carbon::now('UTC');
        return $period === 'day' ? $now->format('Y-m-d') : ($period === 'month' ? $now->format('Y-m') : 'lifetime');
    }

    public function status(): array
    {
        $keys = DB::table('v2_model_key as k')->leftJoin('v2_machine as m', 'm.id', '=', 'k.machine_id')
            ->select('k.id', 'k.machine_id', 'm.name as machine_name', 'k.upstream_id', 'k.key_prefix', 'k.models', 'k.quota', 'k.period', 'k.max_output', 'k.enabled')->orderBy('k.machine_id')->get();
        $buckets = DB::table('v2_model_bucket')->whereIn('key_id', $keys->pluck('id'))
            ->whereIn('period_start', [$this->periodStart('day'), $this->periodStart('month'), 'lifetime'])->get()->keyBy(fn ($b) => $b->key_id . ':' . $b->period_start);
        foreach ($keys as $key) {
            $key->models = json_decode($key->models, true);
            $bucket = $buckets->get($key->id . ':' . $this->periodStart($key->period));
            $key->used = (int) ($bucket->used ?? 0);
            $key->reserved = (int) ($bucket->reserved ?? 0);
            $key->requests = (int) ($bucket->requests ?? 0);
            $key->uncertain = (int) ($bucket->uncertain ?? 0);
            $key->cost = $bucket->cost ?? '0';
            $key->cost_unconfirmed = (int) ($bucket->cost_unconfirmed ?? 0);
            $key->remaining = max(0, $key->quota - $key->used - $key->reserved);
            $key->resets_at = $key->period === 'lifetime' ? null : ($key->period === 'day' ? Carbon::now('UTC')->addDay()->startOfDay() : Carbon::now('UTC')->addMonthNoOverflow()->startOfMonth())->toIso8601String();
        }
        $upstreams = DB::table('v2_model_upstream')->select('id', 'name', 'base_url', 'models', 'enabled', 'protocols', 'protocol_urls', 'pricing')->orderBy('id')->get();
        $quality = DB::table('v2_model_quality_config')->pluck('state', 'upstream_id');
        foreach ($upstreams as $upstream) {
            $upstream->models = json_decode($upstream->models, true);
            $upstream->protocols = $this->protocols($upstream);
            $upstream->protocol_urls = json_decode($upstream->protocol_urls ?? '{}', true) ?: (object) [];
            $upstream->quality_state = $quality[$upstream->id] ?? 'untested';
            $upstream->pricing = json_decode($upstream->pricing ?? '{}', true) ?: (object) [];
        }
        return ['keys' => $keys, 'upstreams' => $upstreams,
            'machines' => DB::table('v2_machine')->select('id', 'name')->orderBy('id')->get(),
            'api_path' => '/api/model/v1', 'timezone' => 'UTC', 'quota_unit' => 'tokens', 'gateway' => $this->gatewayStatus()];
    }

    public function gatewayStatus(): array
    {
        if (!is_file(storage_path('app/integrations/model-relay-gateway.key'))) return ['online' => false];
        try {
            $response = Http::withOptions(['connect_timeout' => 0.5, 'allow_redirects' => false])->timeout(1)->get('http://127.0.0.1:18941/health');
            return ['online' => $response->successful() && $response->json('status') === 'ok'];
        } catch (\Throwable $e) { return ['online' => false]; }
    }

    public function upstreamCredentials(int $id): array
    {
        $row = DB::table('v2_model_upstream')->find($id);
        abort_unless($row, 404, '上游不存在');
        return ['base_url' => $row->base_url, 'api_key' => Crypt::decryptString($row->api_key),
            'enabled' => (bool) $row->enabled, 'models' => json_decode($row->models, true),
            'protocols' => $this->protocols($row), 'protocol_urls' => json_decode($row->protocol_urls ?? '{}', true) ?: (object) []];
    }

    public function connection(string $token, string $protocol): array
    {
        $key = $this->authenticate($token);
        $row = DB::table('v2_model_upstream')->find($key->upstream_id);
        abort_unless($row && $row->enabled && in_array($protocol, $this->protocols($row), true), 403, '上游未启用此协议');
        $urls = json_decode($row->protocol_urls ?? '{}', true) ?: [];
        return ['base_url' => $urls[$protocol === 'responses_ws' ? 'responses' : $protocol] ?? $row->base_url,
            'api_key' => Crypt::decryptString($row->api_key)];
    }

    public function testUpstream(int $id): array
    {
        abort_unless(DB::table('v2_model_upstream')->where('id', $id)->exists(), 404, '上游不存在');
        $path = storage_path('app/integrations/model-relay-gateway.key');
        abort_unless(is_file($path), 409, '中转进程尚未安装');
        try {
            $response = Http::withHeaders(['X-Relay-Secret' => trim(file_get_contents($path))])
                ->withOptions(['connect_timeout' => 1, 'allow_redirects' => false])->timeout(20)->post('http://127.0.0.1:18941/admin/test', ['id' => $id]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) { abort(503, '中转进程未响应'); }
        abort_unless($response->successful() && is_array($response->json()), 503, '中转测试失败，请检查中转进程');
        return $response->json();
    }

    public function upstream(array $input): int
    {
        $data = Validator::make($input, [
            'id' => 'nullable|integer|exists:v2_model_upstream,id', 'name' => 'required|string|max:100',
            'base_url' => 'required|url|max:500', 'api_key' => 'nullable|string|max:4096',
            'models' => 'required|array|min:1|max:100', 'models.*' => 'required|string|max:150|distinct', 'enabled' => 'required|boolean',
            'protocols' => 'sometimes|array|min:1|max:5', 'protocols.*' => 'required|in:' . implode(',', self::PROTOCOLS),
            'protocol_urls' => 'sometimes|array:openai,responses,anthropic,gemini', 'protocol_urls.*' => 'nullable|url|max:500',
            'pricing' => 'sometimes|array|max:100', 'pricing.*' => 'required|array:input,output,cache_read,cache_write',
            'pricing.*.input' => 'required|numeric|min:0|max:10000', 'pricing.*.output' => 'required|numeric|min:0|max:10000',
            'pricing.*.cache_read' => 'required|numeric|min:0|max:10000', 'pricing.*.cache_write' => 'required|numeric|min:0|max:10000',
        ])->validate();
        $url = parse_url($data['base_url']);
        abort_unless(($url['scheme'] ?? '') === 'https' && !isset($url['user'], $url['pass']) && !isset($url['query']) && !isset($url['fragment']), 422, '请填写不带凭据、参数的 HTTPS 基础地址');
        abort_if(isset($url['user']) || isset($url['pass']), 422, '地址不能包含凭据');
        $id = $data['id'] ?? null;
        $previous = $id ? DB::table('v2_model_upstream')->find($id) : null;
        $protocols = $data['protocols'] ?? ($previous ? $this->protocols($previous) : ['openai']);
        abort_if(in_array('responses_ws', $protocols) && !in_array('responses', $protocols), 422, 'WebSocket 需要同时启用 Responses');
        $urls = array_filter($data['protocol_urls'] ?? json_decode($previous->protocol_urls ?? '{}', true) ?? []);
        foreach ($urls as &$base) {
            $parsed = parse_url($base);
            abort_unless(($parsed['scheme'] ?? '') === 'https' && !isset($parsed['user']) && !isset($parsed['pass']) && !isset($parsed['query']) && !isset($parsed['fragment']), 422, '协议地址必须为不带凭据、参数的 HTTPS 地址');
            $base = rtrim($base, '/');
        }
        unset($base);
        $pricing = $data['pricing'] ?? (json_decode($previous->pricing ?? '{}', true) ?: []);
        $pricing = array_intersect_key($pricing, array_flip($data['models']));
        abort_if($pricing && !function_exists('bcmul'), 422, '费用计算需要 PHP bcmath 扩展');
        foreach ($pricing as &$rates) foreach ($rates as &$rate) $rate = number_format((float) $rate, 6, '.', '');
        unset($rates, $rate);
        $secret = trim($data['api_key'] ?? '');
        abort_if(!$secret && !$previous, 422, '请填写上游 API Key');
        abort_if(!$secret && $previous && rtrim($data['base_url'], '/') !== $previous->base_url, 422, '更换上游地址时请重新填写 API Key');
        abort_if(!$secret && $previous && $urls != (json_decode($previous->protocol_urls ?? '{}', true) ?: []), 422, '更换协议地址时请重新填写 API Key');
        unset($data['id']);
        $data['api_key'] = $secret ? Crypt::encryptString($secret) : $previous->api_key;
        $data['models'] = json_encode(array_values($data['models']));
        $data['base_url'] = rtrim($data['base_url'], '/');
        $data['protocols'] = json_encode(array_values(array_unique($protocols)));
        $data['protocol_urls'] = json_encode($urls ?: (object) []);
        $data['pricing'] = json_encode($pricing ?: (object) []);
        $data['updated_at'] = Carbon::now('UTC');
        if ($id) DB::table('v2_model_upstream')->where('id', $id)->update($data);
        else $id = DB::table('v2_model_upstream')->insertGetId($data + ['created_at' => Carbon::now('UTC')]);
        return (int) $id;
    }

    public function saveKey(array $input): array
    {
        $data = Validator::make($input, [
            'id' => 'nullable|integer|exists:v2_model_key,id', 'machine_id' => 'required|integer|exists:v2_machine,id',
            'upstream_id' => 'required|integer|exists:v2_model_upstream,id', 'models' => 'required|array|min:1|max:100',
            'models.*' => 'required|string|max:150|distinct', 'quota' => 'required|integer|min:1|max:1000000000000',
            'period' => 'required|in:day,month,lifetime', 'max_output' => 'required|integer|min:1|max:131072', 'enabled' => 'required|boolean',
        ])->validate();
        $upstream = DB::table('v2_model_upstream')->find($data['upstream_id']);
        abort_if(array_diff($data['models'], json_decode($upstream->models, true)), 422, '模型必须属于所选上游');
        return DB::transaction(function () use ($data) {
            // 串行化同一机器的首次签发，避免并发创建撞唯一键变成 500。
            abort_unless(DB::table('v2_machine')->where('id', $data['machine_id'])->lockForUpdate()->first(), 422, '机器已删除');
            $id = $data['id'] ?? null;
            $old = $id ? DB::table('v2_model_key')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($old && ((int) $old->machine_id !== (int) $data['machine_id'] || $old->period !== $data['period']), 422, '机器和计费周期创建后不可更换，请调整额度或重置 Key');
            abort_if(!$old && DB::table('v2_model_key')->where('machine_id', $data['machine_id'])->exists(), 422, '此机器已有 Key，请编辑已有配置');
            unset($data['id']);
            $data['models'] = json_encode(array_values($data['models']));
            $data['updated_at'] = Carbon::now('UTC');
            $plain = null;
            if ($id) DB::table('v2_model_key')->where('id', $id)->update($data);
            else {
                $plain = 'bc-' . bin2hex(random_bytes(32));
                $id = DB::table('v2_model_key')->insertGetId($data + ['key_hash' => hash('sha256', $plain), 'key_prefix' => substr($plain, 0, 11), 'created_at' => Carbon::now('UTC')]);
            }
            return ['id' => $id, 'key' => $plain];
        });
    }

    public function keyAction(int $id, string $action): ?string
    {
        return DB::transaction(function () use ($id, $action) {
            abort_unless(DB::table('v2_model_key')->where('id', $id)->lockForUpdate()->first(), 404);
            $plain = $action === 'rotate' ? 'bc-' . bin2hex(random_bytes(32)) : null;
            $data = $plain ? ['key_hash' => hash('sha256', $plain), 'key_prefix' => substr($plain, 0, 11)] : ['enabled' => false];
            DB::table('v2_model_key')->where('id', $id)->update($data + ['updated_at' => Carbon::now('UTC')]);
            return $plain;
        });
    }

    private function authenticate(string $token, bool $lock = false): object
    {
        $query = DB::table('v2_model_key')->where('key_hash', hash('sha256', $token));
        if ($lock) $query->lockForUpdate();
        $key = $query->first();
        abort_unless($key && $key->enabled, 401, 'Key 无效或已停用');
        abort_unless(DB::table('v2_machine')->where('id', $key->machine_id)->exists(), 401, '绑定机器已删除');
        return $key;
    }

    public function models(string $token, ?string $protocol = null): array
    {
        $key = $this->authenticate($token);
        $upstream = DB::table('v2_model_upstream')->find($key->upstream_id);
        abort_unless($upstream && $upstream->enabled, 503, '上游已停用');
        abort_if($protocol && !in_array($protocol, $this->protocols($upstream), true), 403, '此协议未启用');
        return array_values(array_intersect(json_decode($key->models, true), json_decode($upstream->models, true)));
    }

    public function reserve(array $input): array
    {
        $d = Validator::make($input, ['key' => 'required|string|max:100', 'request_id' => 'required|string|size:32',
            'model' => 'required|string|max:150', 'input_bound' => 'required|integer|min:1|max:2097152',
            'max_output' => 'nullable|integer|min:1|max:131072', 'protocol' => 'sometimes|in:' . implode(',', self::PROTOCOLS),
            'previous_response_id' => 'nullable|string|max:200'])->validate();
        return DB::transaction(function () use ($d) {
            $key = $this->authenticate($d['key'], true);
            abort_if(DB::table('v2_model_usage')->where('request_id', $d['request_id'])->exists(), 409, '重复请求');
            $upstream = DB::table('v2_model_upstream')->find($key->upstream_id);
            abort_unless($upstream && $upstream->enabled, 503, '上游已停用');
            $protocol = $d['protocol'] ?? 'openai';
            abort_unless(in_array($protocol, $this->protocols($upstream), true), 403, '此协议未启用');
            if (!empty($d['previous_response_id'])) {
                $parent = DB::table('v2_model_usage')->where('key_id', $key->id)->where('upstream_id', $upstream->id)
                    ->where('response_id', $d['previous_response_id'])->whereIn('protocol', ['responses', 'responses_ws'])->where('status', 'complete')->first();
                abort_unless($parent && $parent->input_tokens !== null && $parent->output_tokens !== null, 422, '上一轮响应不属于此 Key 或用量尚未确认，请发送完整上下文');
                $d['input_bound'] += $parent->input_tokens + $parent->output_tokens;
            }
            abort_unless(in_array($d['model'], json_decode($key->models, true), true) && in_array($d['model'], json_decode($upstream->models, true), true), 403, '模型未授权');
            $output = $d['max_output'] ?? $key->max_output;
            abort_if($output > $key->max_output, 422, '输出上限超过此 Key 限制');
            abort_if(DB::table('v2_model_usage')->where('key_id', $key->id)->where('status', 'pending')->count() >= 4, 429, '此机器并发请求已达上限');
            $period = $this->periodStart($key->period);
            $bucket = DB::table('v2_model_bucket')->where('key_id', $key->id)->where('period_start', $period)->first();
            if (!$bucket) {
                DB::table('v2_model_bucket')->insert(['key_id' => $key->id, 'period_start' => $period]);
                $bucket = DB::table('v2_model_bucket')->where('key_id', $key->id)->where('period_start', $period)->first();
            }
            $reserved = $d['input_bound'] + $output;
            $pricing = (json_decode($upstream->pricing ?? '{}', true) ?: [])[$d['model']] ?? null;
            abort_if($bucket->used + $bucket->reserved + $reserved > $key->quota, 429, '机器额度不足，请缩短输入或输出上限');
            DB::table('v2_model_bucket')->where('id', $bucket->id)->increment('reserved', $reserved);
            DB::table('v2_model_usage')->insert(['request_id' => $d['request_id'], 'key_id' => $key->id, 'machine_id' => $key->machine_id,
                'upstream_id' => $upstream->id, 'bucket_id' => $bucket->id, 'model' => $d['model'], 'protocol' => $protocol, 'pricing' => $pricing ? json_encode($pricing) : null, 'reserved' => $reserved, 'created_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC')]);
            $urls = json_decode($upstream->protocol_urls ?? '{}', true) ?: [];
            return ['base_url' => $urls[$protocol === 'responses_ws' ? 'responses' : $protocol] ?? $upstream->base_url, 'api_key' => Crypt::decryptString($upstream->api_key), 'max_output' => (int) $output];
        });
    }

    public function settle(array $input): void
    {
        $d = Validator::make($input, ['request_id' => 'required|string|size:32', 'status' => 'required|in:complete,rejected,interrupted,unknown',
            'input_tokens' => 'nullable|integer|min:0|max:1000000000', 'output_tokens' => 'nullable|integer|min:0|max:1000000000',
            'http_status' => 'required|integer|min:0|max:599', 'duration_ms' => 'required|integer|min:0|max:3600000',
            'response_id' => 'nullable|string|max:200',
            'cache_read_tokens' => 'nullable|integer|min:0|max:1000000000', 'cache_write_tokens' => 'nullable|integer|min:0|max:1000000000',
            'error_code' => 'nullable|in:upstream_network,upstream_rejected,cloudflare_blocked,invalid_response,stream_interrupted'])->validate();
        DB::transaction(function () use ($d) {
            $row = DB::table('v2_model_usage')->where('request_id', $d['request_id'])->first();
            abort_unless($row, 404, '请求不存在');
            DB::table('v2_model_key')->where('id', $row->key_id)->lockForUpdate()->first();
            $row = DB::table('v2_model_usage')->where('request_id', $d['request_id'])->lockForUpdate()->first();
            $hasUsage = isset($d['input_tokens'], $d['output_tokens']);
            $reconcile = $row->status === 'estimated' && ($hasUsage || $d['status'] === 'rejected');
            if ($row->status !== 'pending' && !$reconcile) return;
            // 缺少 usage 或中断不能按零消费释放，保守扣除预留量并单独展示。
            $charged = $hasUsage ? $d['input_tokens'] + $d['output_tokens'] : ($d['status'] === 'rejected' ? 0 : $row->reserved);
            $uncertain = !$hasUsage && $d['status'] !== 'rejected';
            if ($uncertain) $d['status'] = 'estimated';
            $d['error_code'] = $d['error_code'] ?? null;
            $billing = ModelRelayBilling::calculate(json_decode($row->pricing ?? 'null', true), $d['input_tokens'] ?? null, $d['output_tokens'] ?? null, $d['cache_read_tokens'] ?? null, $d['cache_write_tokens'] ?? null);
            if ($d['status'] === 'rejected' && !$hasUsage) $billing = ['cost' => '0', 'cost_status' => 'confirmed'];
            $d += $billing;
            DB::table('v2_model_usage')->where('request_id', $row->request_id)->update($d + ['charged' => $charged, 'updated_at' => Carbon::now('UTC')]);
            $bucket = DB::table('v2_model_bucket')->where('id', $row->bucket_id)->first();
            DB::table('v2_model_bucket')->where('id', $row->bucket_id)->update([
                'reserved' => max(0, $bucket->reserved - ($reconcile ? 0 : $row->reserved)), 'used' => max(0, $bucket->used + $charged - ($reconcile ? $row->charged : 0)), 'requests' => $bucket->requests + ($reconcile ? 0 : 1),
                'input_tokens' => $bucket->input_tokens + ($d['input_tokens'] ?? 0), 'output_tokens' => $bucket->output_tokens + ($d['output_tokens'] ?? 0),
                'uncertain' => max(0, $bucket->uncertain + (int) $uncertain - (int) $reconcile),
                'cost' => function_exists('bcadd') ? bcadd(bcsub((string) $bucket->cost, (string) ($reconcile ? ($row->cost ?? '0') : '0'), 12), (string) ($billing['cost'] ?? '0'), 12) : $bucket->cost,
                'cost_unconfirmed' => max(0, $bucket->cost_unconfirmed + (int) ($billing['cost_status'] !== 'confirmed') - (int) ($reconcile && $row->cost_status !== 'confirmed')),
            ]);
        });
    }
}
