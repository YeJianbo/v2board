<?php

namespace Tests\Unit;

use App\Services\ModelRelayService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModelRelayTest extends TestCase
{
    private ModelRelayService $relay;
    private string $key;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        Schema::create('v2_machine', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        DB::table('v2_machine')->insert(['id' => 9, 'name' => 'test']);
        require_once database_path('migrations/2026_09_12_000002_create_model_relay_tables.php');
        (new \CreateModelRelayTables())->up();
        require_once database_path('migrations/2026_09_12_000004_extend_model_relay_protocols_quality.php');
        (new \ExtendModelRelayProtocolsQuality())->up();
        $this->relay = new ModelRelayService();
        $id = $this->relay->upstream(['name' => 'test', 'base_url' => 'https://example.test/v1', 'api_key' => 'SECRET-UPSTREAM', 'models' => ['model-a'], 'enabled' => true]);
        $this->key = $this->relay->saveKey(['machine_id' => 9, 'upstream_id' => $id, 'models' => ['model-a'], 'quota' => 10000, 'period' => 'month', 'max_output' => 1000, 'enabled' => true])['key'];
    }
    private function reserve(string $id): array
    {
        return $this->relay->reserve(['key' => $this->key, 'request_id' => str_pad($id, 32, '0'), 'model' => 'model-a', 'input_bound' => 500]);
    }
    private function settle(string $id, array $extra = []): void
    {
        $this->relay->settle($extra + ['request_id' => str_pad($id, 32, '0'), 'status' => 'complete', 'http_status' => 200, 'duration_ms' => 600]);
    }
    public function test_secrets_and_machine_keys_are_not_exposed(): void
    {
        $status = json_encode($this->relay->status());
        $this->assertStringNotContainsString($this->key, $status);
        $this->assertStringNotContainsString('SECRET-UPSTREAM', $status);
        $this->assertStringNotContainsString('SECRET-UPSTREAM', DB::table('v2_model_upstream')->value('api_key'));
        $this->assertSame(hash('sha256', $this->key), DB::table('v2_model_key')->value('key_hash'));
        $this->assertSame(['model-a'], $this->relay->models($this->key));
    }
    public function test_reservation_settles_once_and_rotating_preserves_usage(): void
    {
        $this->reserve('a');
        $this->assertEquals(1500, DB::table('v2_model_bucket')->value('reserved'));
        $this->settle('a', ['input_tokens' => 120, 'output_tokens' => 80]);
        $this->settle('a', ['input_tokens' => 120, 'output_tokens' => 80]);
        $this->assertEquals(200, DB::table('v2_model_bucket')->value('used'));
        $this->assertEquals(0, DB::table('v2_model_bucket')->value('reserved'));
        $this->assertEquals(1, DB::table('v2_model_bucket')->value('requests'));
        $newKey = $this->relay->keyAction(1, 'rotate');
        $this->assertNotSame($this->key, $newKey);
        $this->assertEquals(200, $this->relay->status()['keys'][0]->used);
        try { $this->relay->models($this->key); $this->fail('Old key accepted'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(401, $e->getStatusCode()); }
    }
    public function test_missing_usage_is_charged_conservatively_but_rejection_releases(): void
    {
        $this->reserve('a'); $this->settle('a', ['status' => 'interrupted']);
        $this->assertEquals(1500, DB::table('v2_model_bucket')->value('used'));
        $this->assertSame('estimated', DB::table('v2_model_usage')->value('status'));
        $this->reserve('b'); $this->settle('b', ['status' => 'rejected', 'http_status' => 429]);
        $this->assertEquals(1500, DB::table('v2_model_bucket')->value('used'));
        $this->assertEquals(1, DB::table('v2_model_bucket')->value('uncertain'));
    }
    public function test_quota_and_revocation_block_new_calls(): void
    {
        DB::table('v2_model_key')->update(['quota' => 2000]);
        $this->reserve('a');
        try { $this->reserve('b'); $this->fail('Exceeded quota'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(429, $e->getStatusCode()); }
        $this->relay->keyAction(1, 'revoke');
        try { $this->reserve('c'); $this->fail('Revoked key accepted'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(401, $e->getStatusCode()); }
    }
    public function test_calendar_period_rollover_does_not_move_old_settlement(): void
    {
        \Carbon\Carbon::setTestNow('2026-01-31 23:59:59 UTC');
        try {
            $this->reserve('a');
            \Carbon\Carbon::setTestNow('2026-02-01 00:00:01 UTC');
            $this->reserve('b');
            $this->settle('a', ['input_tokens' => 10, 'output_tokens' => 20]);
            $this->assertEquals(30, DB::table('v2_model_bucket')->where('period_start', '2026-01')->value('used'));
            $this->assertEquals(0, $this->relay->status()['keys'][0]->used);
            $this->assertEquals(1500, $this->relay->status()['keys'][0]->reserved);
        } finally { \Carbon\Carbon::setTestNow(); }
    }
    public function test_allowlist_duplicate_reservation_and_internal_auth(): void
    {
        $this->postJson('/api/v2/model-relay/internal/reserve', [])->assertStatus(403);
        $this->reserve('a');
        try { $this->reserve('a'); $this->fail('Duplicate accepted'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        try { $this->relay->reserve(['key' => $this->key, 'request_id' => str_repeat('z', 32), 'model' => 'unauthorized', 'input_bound' => 100]); $this->fail('Unauthorized model'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }
    public function test_late_usage_reconciles_estimate_once(): void
    {
        $this->reserve('late'); $this->settle('late', ['status' => 'unknown']);
        $this->assertEquals(1500, DB::table('v2_model_bucket')->value('used'));
        $this->settle('late', ['input_tokens' => 50, 'output_tokens' => 25]);
        $this->settle('late', ['input_tokens' => 50, 'output_tokens' => 25]);
        $bucket = DB::table('v2_model_bucket')->first();
        $this->assertEquals(75, $bucket->used);
        $this->assertEquals(1, $bucket->requests);
        $this->assertEquals(0, $bucket->uncertain);
        $this->assertEquals(0, $bucket->reserved);
    }
    public function test_changing_upstream_address_requires_new_credentials(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->relay->upstream(['id' => 1, 'name' => 'test', 'base_url' => 'https://another.example/v1', 'models' => ['model-a'], 'enabled' => true]);
    }
    public function test_retention_does_not_clear_quota_counters(): void
    {
        $this->reserve('old'); $this->settle('old', ['input_tokens' => 10, 'output_tokens' => 20]);
        DB::table('v2_model_usage')->update(['created_at' => \Carbon\Carbon::now('UTC')->subDays(100)]);
        $this->artisan('model-relay:maintain')->assertExitCode(0);
        $this->assertEquals(0, DB::table('v2_model_usage')->count());
        $this->assertEquals(30, DB::table('v2_model_bucket')->value('used'));
    }
    public function test_explicit_protocol_selection_and_response_ownership(): void
    {
        try { $this->relay->reserve(['key' => $this->key, 'request_id' => str_repeat('p', 32), 'model' => 'model-a', 'protocol' => 'responses', 'input_bound' => 50]); $this->fail('Disabled protocol accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->relay->upstream(['id' => 1, 'name' => 'test', 'base_url' => 'https://example.test/v1', 'models' => ['model-a'], 'protocols' => ['openai', 'responses', 'responses_ws'], 'enabled' => true]);
        $this->relay->reserve(['key' => $this->key, 'request_id' => str_repeat('a', 32), 'model' => 'model-a', 'protocol' => 'responses', 'input_bound' => 50]);
        $this->relay->settle(['request_id' => str_repeat('a', 32), 'status' => 'complete', 'http_status' => 200, 'duration_ms' => 20, 'response_id' => 'resp-own', 'input_tokens' => 40, 'output_tokens' => 10]);
        $this->relay->reserve(['key' => $this->key, 'request_id' => str_repeat('b', 32), 'model' => 'model-a', 'protocol' => 'responses_ws', 'input_bound' => 50, 'previous_response_id' => 'resp-own']);
        $this->assertEquals(1100, DB::table('v2_model_usage')->where('request_id', str_repeat('b', 32))->value('reserved'));
        try { $this->relay->reserve(['key' => $this->key, 'request_id' => str_repeat('c', 32), 'model' => 'model-a', 'protocol' => 'responses', 'input_bound' => 50, 'previous_response_id' => 'resp-other']); $this->fail('Foreign response accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }
    public function test_protocol_url_changes_require_explicit_key_and_are_used(): void
    {
        $input = ['id' => 1, 'name' => 'test', 'base_url' => 'https://example.test/v1', 'models' => ['model-a'], 'protocols' => ['gemini'], 'protocol_urls' => ['gemini' => 'https://google.example/v1beta'], 'enabled' => true];
        try { $this->relay->upstream($input); $this->fail('Key silently sent to another address'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->relay->upstream($input + ['api_key' => 'NEW-SECRET']);
        $result = $this->relay->reserve(['key' => $this->key, 'request_id' => str_repeat('g', 32), 'model' => 'model-a', 'protocol' => 'gemini', 'input_bound' => 50]);
        $this->assertSame('https://google.example/v1beta', $result['base_url']);
    }
    public function test_price_snapshot_and_late_cache_usage_are_idempotent(): void
    {
        $input = ['id' => 1, 'name' => 'test', 'base_url' => 'https://example.test/v1', 'models' => ['model-a'], 'enabled' => true,
            'pricing' => ['model-a' => ['input' => 0.002, 'output' => 0.006, 'cache_read' => 0.0005, 'cache_write' => 0]]];
        $this->relay->upstream($input); $this->reserve('priced');
        $this->settle('priced', ['status' => 'unknown']);
        $input['pricing']['model-a']['input'] = 100; $this->relay->upstream($input);
        $details = ['input_tokens' => 214, 'output_tokens' => 418, 'cache_read_tokens' => 192, 'cache_write_tokens' => 0];
        $this->settle('priced', $details); $this->settle('priced', $details);
        $row = DB::table('v2_model_usage')->first(); $bucket = DB::table('v2_model_bucket')->first();
        $this->assertEquals(0.000002648, $row->cost);
        $this->assertEquals(0.000002648, $bucket->cost);
        $this->assertSame('confirmed', $row->cost_status);
        $this->assertEquals(0, $bucket->cost_unconfirmed);
        $this->assertEquals(632, $bucket->used);
    }
}
