<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ModelQualityService
{
    public function defaults(object $upstream): array
    {
        $protocols = array_values(array_diff(app(ModelRelayService::class)->protocols($upstream), ['responses_ws']));
        return ['enabled' => false, 'interval_minutes' => 360, 'model' => json_decode($upstream->models, true)[0] ?? '',
            'protocol' => $protocols[0] ?? 'openai', 'max_output' => 256, 'threshold' => 70, 'drop_threshold' => 20,
            'consecutive' => 2, 'notify' => false, 'cases' => [], 'suite_version' => 1];
    }

    public function config(int $id): array
    {
        $upstream = DB::table('v2_model_upstream')->find($id);
        abort_unless($upstream, 404, '上游不存在');
        $row = DB::table('v2_model_quality_config')->where('upstream_id', $id)->first();
        return ['settings' => $row ? json_decode($row->settings, true) : $this->defaults($upstream),
            'state' => $row->state ?? 'untested', 'baseline_score' => $row->baseline_score ?? null,
            'last_score' => $row->last_score ?? null, 'bad_streak' => $row->bad_streak ?? 0, 'next_run_at' => $row->next_run_at ?? null];
    }

    private function identity(array $s): array
    {
        return array_intersect_key($s, array_flip(['model', 'protocol', 'max_output', 'cases', 'suite_version']));
    }

    public function save(int $id, array $input): array
    {
        $data = Validator::make($input, [
            'enabled' => 'required|boolean', 'interval_minutes' => 'required|integer|min:15|max:10080',
            'model' => 'required|string|max:150', 'protocol' => 'required|in:openai,responses,anthropic,gemini',
            'max_output' => 'required|integer|min:16|max:1024', 'threshold' => 'required|integer|min:0|max:100',
            'drop_threshold' => 'required|integer|min:1|max:100', 'consecutive' => 'required|integer|min:1|max:10', 'notify' => 'required|boolean',
            'cases' => 'present|array|max:8', 'cases.*.name' => 'required|string|max:60',
            'cases.*.prompt' => 'required|string|max:2000', 'cases.*.expected' => 'required|string|max:200',
        ])->validate();
        $upstream = DB::table('v2_model_upstream')->find($id);
        abort_unless($upstream, 404, '上游不存在');
        abort_unless(in_array($data['model'], json_decode($upstream->models, true), true), 422, '检测模型必须属于所选上游');
        abort_unless(in_array($data['protocol'], app(ModelRelayService::class)->protocols($upstream), true), 422, '上游未开启检测所用协议');
        $data['suite_version'] = 1;
        return DB::transaction(function () use ($data, $id) {
            DB::table('v2_model_upstream')->where('id', $id)->lockForUpdate()->first();
            $old = DB::table('v2_model_quality_config')->where('upstream_id', $id)->first();
            $update = ['settings' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'next_run_at' => $data['enabled'] ? Carbon::now('UTC')->addMinutes($data['interval_minutes']) : null, 'updated_at' => Carbon::now('UTC')];
            if ($old && $this->identity(json_decode($old->settings, true)) != $this->identity($data)) {
                $update += ['baseline_run_id' => null, 'baseline_score' => null, 'last_score' => null, 'state' => 'untested', 'bad_streak' => 0];
            }
            if ($old) DB::table('v2_model_quality_config')->where('upstream_id', $id)->update($update);
            else DB::table('v2_model_quality_config')->insert($update + ['upstream_id' => $id, 'created_at' => Carbon::now('UTC')]);
            return $this->config($id);
        });
    }

    public function enqueue(int $id, string $source = 'manual'): int
    {
        return DB::transaction(function () use ($id, $source) {
            $upstream = DB::table('v2_model_upstream')->where('id', $id)->lockForUpdate()->first();
            abort_unless($upstream && $upstream->enabled, 422, '请先启用上游');
            $existing = DB::table('v2_model_quality_run')->where('upstream_id', $id)->whereIn('status', ['queued', 'running'])->first();
            if ($existing) return (int) $existing->id;
            $s = $this->config($id)['settings'];
            if (!DB::table('v2_model_quality_config')->where('upstream_id', $id)->exists()) $this->save($id, $s);
            $now = Carbon::now('UTC');
            $prices = (json_decode($upstream->pricing ?? '{}', true) ?: [])[$s['model']] ?? null;
            return DB::table('v2_model_quality_run')->insertGetId(['upstream_id' => $id, 'model' => $s['model'], 'protocol' => $s['protocol'],
                'source' => $source, 'status' => 'queued', 'pricing' => $prices ? json_encode($prices) : null, 'settings' => json_encode($s, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now]);
        });
    }

    public function cases(array $settings): array
    {
        if ($settings['cases']) return $settings['cases'];
        $n = random_int(24, 49); $give = random_int(5, 11); $add = random_int(3, 9); $eat = random_int(2, 6);
        $children = random_int(4, 8); $each = random_int(3, 9); $left = random_int(1, 5);
        $rounds = random_int(3, 6); $daily = random_int(3, 5); $gain = random_int(1, 2); $initial = random_int($rounds * $daily + 10, $rounds * $daily + 25);
        return [
            ['name' => '糖果收支', 'prompt' => "盒子原有{$n}颗糖，送出{$give}颗，收到{$add}颗，又吃掉{$eat}颗。现在盒子里有多少颗糖？", 'expected' => (string) ($n - $give + $add - $eat)],
            ['name' => '糖果逆推', 'prompt' => "把一盒糖分给{$children}个孩子，每人{$each}颗，最后还剩{$left}颗。盒子最初有多少颗糖？", 'expected' => (string) ($children * $each + $left)],
            ['name' => '多轮条件', 'prompt' => "盒中原有{$initial}颗糖。连续{$rounds}天，每天先吃掉{$daily}颗，再放入{$gain}颗。最后一天也放入糖。第{$rounds}天结束后剩多少颗？", 'expected' => (string) ($initial + $rounds * ($gain - $daily))],
        ];
    }

    public function probe(int $upstreamId, array $settings, string $prompt): array
    {
        $path = storage_path('app/integrations/model-relay-gateway.key');
        if (!is_file($path)) return ['ok' => false, 'error_code' => 'gateway_unavailable', 'tokens' => null];
        try {
            $response = Http::withHeaders(['X-Relay-Secret' => trim(file_get_contents($path))])->withOptions(['connect_timeout' => 1, 'allow_redirects' => false])->timeout(45)
                ->post('http://127.0.0.1:18941/admin/probe', ['id' => $upstreamId, 'model' => $settings['model'], 'protocol' => $settings['protocol'], 'max_output' => $settings['max_output'], 'prompt' => $prompt]);
            return $response->successful() && is_array($response->json()) ? $response->json() : ['ok' => false, 'error_code' => 'gateway_or_protocol', 'tokens' => null];
        } catch (\Throwable $e) { return ['ok' => false, 'error_code' => 'upstream_network', 'tokens' => null]; }
    }

    public function answer(string $text): ?string
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($text));
        $data = json_decode($text, true);
        $value = is_array($data) ? ($data['answer'] ?? null) : null;
        return is_scalar($value) ? trim((string) $value) : null;
    }

    public function execute(int $id): void
    {
        $claimed = DB::table('v2_model_quality_run')->where('id', $id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC')]);
        if (!$claimed) return;
        $run = DB::table('v2_model_quality_run')->find($id);
        $settings = json_decode($run->settings, true);
        $results = []; $tokens = 0; $knownUsage = true; $completed = true; $passed = 0;
        $cost = '0'; $costStatus = 'confirmed'; $knownCost = true;
        foreach ($this->cases($settings) as $case) {
            $result = $this->probe($run->upstream_id, $settings, $case['prompt'] . "\n" . '只输出一个 JSON 对象，格式为 {"answer":"你的答案"}，不要解释。');
            $text = mb_substr((string) ($result['text'] ?? ''), 0, 1500);
            $answer = $this->answer($text);
            $correct = !empty($result['ok']) && $answer !== null && $answer === trim($case['expected']);
            $passed += (int) $correct;
            $completed = $completed && !empty($result['ok']);
            if (isset($result['tokens'])) $tokens += $result['tokens']; else $knownUsage = false;
            $billing = ModelRelayBilling::calculate(json_decode($run->pricing ?? 'null', true), $result['input_tokens'] ?? null, $result['output_tokens'] ?? null, $result['cache_read_tokens'] ?? null, $result['cache_write_tokens'] ?? null);
            if ($billing['cost'] !== null) $cost = bcadd($cost, $billing['cost'], 12); else $knownCost = false;
            if ($billing['cost_status'] !== 'confirmed') $costStatus = $billing['cost_status'];
            $results[] = $case + ['actual' => $text, 'answer' => $answer, 'passed' => $correct,
                'error_code' => $result['error_code'] ?? null, 'http_status' => $result['http_status'] ?? 0, 'duration_ms' => $result['duration_ms'] ?? null, 'tokens' => $result['tokens'] ?? null,
                'cache_read_tokens' => $result['cache_read_tokens'] ?? null, 'cache_write_tokens' => $result['cache_write_tokens'] ?? null] + $billing;
            DB::table('v2_model_quality_run')->where('id', $id)->update(['results' => json_encode($results, JSON_UNESCAPED_UNICODE), 'updated_at' => Carbon::now('UTC')]);
            // A failed connection is not evidence of reduced reasoning ability; stop spending on this run.
            if (empty($result['ok'])) break;
        }
        $score = $completed ? (int) round(100 * $passed / count($results)) : null;
        $this->complete($run, $settings, $results, $score, $knownUsage ? $tokens : null, $knownCost ? $cost : null, $costStatus);
    }

    private function complete(object $run, array $settings, array $results, ?int $score, ?int $tokens, ?string $cost, string $costStatus): void
    {
        $notification = DB::transaction(function () use ($run, $settings, $results, $score, $tokens, $cost, $costStatus) {
            $config = DB::table('v2_model_quality_config')->where('upstream_id', $run->upstream_id)->lockForUpdate()->first();
            $alert = false; $state = 'unavailable';
            if ($config && $this->identity(json_decode($config->settings, true)) == $this->identity($settings)) {
                $bad = $score !== null && ($score < $settings['threshold'] || ($config->baseline_score !== null && $score <= $config->baseline_score - $settings['drop_threshold']));
                $streak = $bad ? $config->bad_streak + 1 : 0;
                if ($score !== null) $state = $bad ? ($streak >= $settings['consecutive'] ? ($config->baseline_score === null ? 'below_threshold' : 'degraded') : 'watch') : 'healthy';
                $alert = in_array($state, ['degraded', 'below_threshold']) && $state !== $config->state;
                DB::table('v2_model_quality_config')->where('upstream_id', $run->upstream_id)->update(['state' => $state, 'last_score' => $score, 'bad_streak' => $streak, 'updated_at' => Carbon::now('UTC')]);
            }
            DB::table('v2_model_quality_run')->where('id', $run->id)->update(['status' => $score === null ? 'error' : 'complete', 'score' => $score,
                'tokens' => $tokens, 'cost' => $cost, 'cost_status' => $costStatus, 'results' => json_encode($results, JSON_UNESCAPED_UNICODE), 'alert' => $alert, 'finished_at' => Carbon::now('UTC'), 'updated_at' => Carbon::now('UTC')]);
            return $alert && $settings['notify'];
        });
        if ($notification) {
            try { app(TelegramService::class)->sendMessageWithAdmin("模型能力测试提醒\n上游 #{$run->upstream_id} · {$run->model}\n本轮通过率 {$score}%\n连续结果低于设定标准，请在模型分发页面查看失败题；此结果不等同于模型身份或智力鉴定。", false, ''); }
            catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('Model quality notification failed', ['run_id' => $run->id]); }
        }
    }

    public function baseline(int $id): void
    {
        $run = DB::table('v2_model_quality_run')->find($id);
        abort_unless($run && $run->status === 'complete' && $run->score !== null, 422, '只能把完整测试设为基线');
        $settings = $this->config($run->upstream_id)['settings'];
        abort_unless($this->identity($settings) == $this->identity(json_decode($run->settings, true)), 409, '模型、题库或输出参数已变化，请重新测试');
        DB::table('v2_model_quality_config')->where('upstream_id', $run->upstream_id)->update(['baseline_run_id' => $id, 'baseline_score' => $run->score, 'bad_streak' => 0, 'state' => 'untested', 'updated_at' => Carbon::now('UTC')]);
    }
}
