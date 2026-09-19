<?php

namespace Tests\Unit;

use App\Services\ModelQualityService;
use App\Services\ModelRelayService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelQualityTest extends TestCase
{
    private ModelQualityService $quality;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        require_once database_path('migrations/2026_09_12_000002_create_model_relay_tables.php'); (new \CreateModelRelayTables())->up();
        require_once database_path('migrations/2026_09_12_000004_extend_model_relay_protocols_quality.php'); (new \ExtendModelRelayProtocolsQuality())->up();
        app(ModelRelayService::class)->upstream(['name' => 'test', 'base_url' => 'https://example.test/v1', 'api_key' => 'secret', 'models' => ['a'], 'enabled' => true]);
        $this->quality = new ModelQualityService();
    }
    private function configure(array $override = []): array
    {
        $s = $this->quality->config(1)['settings'];
        $s['cases'] = [['name' => 'candy', 'prompt' => '有十颗糖，吃三颗后剩多少？', 'expected' => '7']];
        $s = array_replace($s, $override); $this->quality->save(1, $s); return $s;
    }
    private function runner(array $response): ModelQualityService
    {
        return new class($response) extends ModelQualityService {
            private array $response;
            public function __construct(array $response) { $this->response = $response; }
            public function probe(int $id, array $s, string $p): array { return $this->response; }
        };
    }
    public function test_manual_queue_is_idempotent_and_does_not_enable_scheduling(): void
    {
        $this->configure();
        $first = $this->quality->enqueue(1);
        $this->assertSame($first, $this->quality->enqueue(1));
        $this->assertFalse($this->quality->config(1)['settings']['enabled']);
        $this->assertNull($this->quality->config(1)['next_run_at']);
        $this->runner(['ok' => true, 'text' => '{"answer":"7"}', 'tokens' => 12])->execute($first);
        $this->assertEquals(100, DB::table('v2_model_quality_run')->value('score'));
        $this->assertEquals(12, DB::table('v2_model_quality_run')->value('tokens'));
        $this->assertEquals(0, DB::table('v2_model_usage')->count());
    }
    public function test_baseline_drop_requires_consecutive_completed_failures(): void
    {
        $this->configure();
        $first = $this->quality->enqueue(1); $this->runner(['ok' => true, 'text' => '{"answer":7}', 'tokens' => 10])->execute($first);
        $this->quality->baseline($first);
        $bad = $this->runner(['ok' => true, 'text' => '{"answer":"8"}', 'tokens' => 10]);
        $bad->execute($this->quality->enqueue(1));
        $this->assertSame('watch', $this->quality->config(1)['state']);
        $bad->execute($this->quality->enqueue(1));
        $this->assertSame('degraded', $this->quality->config(1)['state']);
        $this->assertEquals(1, DB::table('v2_model_quality_run')->where('alert', true)->count());
        $bad->execute($this->quality->enqueue(1));
        $this->assertEquals(1, DB::table('v2_model_quality_run')->where('alert', true)->count());
    }
    public function test_connection_failure_is_not_a_failed_intelligence_score(): void
    {
        $this->configure(['consecutive' => 1]);
        $this->runner(['ok' => false, 'error_code' => 'upstream_network', 'tokens' => null])->execute($this->quality->enqueue(1));
        $this->assertNull(DB::table('v2_model_quality_run')->value('score'));
        $this->assertNull(DB::table('v2_model_quality_run')->value('tokens'));
        $this->assertSame('unavailable', $this->quality->config(1)['state']);
        $this->assertEquals(0, DB::table('v2_model_quality_run')->where('alert', true)->count());
    }
    public function test_suite_changes_invalidate_baseline_and_stale_runs_cannot_change_state(): void
    {
        $s = $this->configure(); $id = $this->quality->enqueue(1);
        $this->runner(['ok' => true, 'text' => '{"answer":7}', 'tokens' => 10])->execute($id); $this->quality->baseline($id);
        $queued = $this->quality->enqueue(1);
        $s['cases'][0]['expected'] = '8'; $this->quality->save(1, $s);
        $this->assertNull($this->quality->config(1)['baseline_score']);
        $this->runner(['ok' => true, 'text' => '{"answer":0}', 'tokens' => 10])->execute($queued);
        $this->assertSame('untested', $this->quality->config(1)['state']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class); $this->quality->baseline($id);
    }
    public function test_scheduler_only_dispatches_explicitly_enabled_configs(): void
    {
        $s = $this->configure();
        $this->artisan('model-relay:quality')->assertExitCode(0);
        $this->assertEquals(0, DB::table('v2_model_quality_run')->count());
        $this->quality->save(1, array_replace($s, ['enabled' => true, 'interval_minutes' => 15]));
        DB::table('v2_model_quality_config')->update(['next_run_at' => \Carbon\Carbon::now('UTC')->subMinute()]);
        $this->app->instance(ModelQualityService::class, $this->runner(['ok' => true, 'text' => '{"answer":7}', 'tokens' => 10]));
        $this->artisan('model-relay:quality')->assertExitCode(0);
        $this->assertSame('scheduled', DB::table('v2_model_quality_run')->value('source'));
        $this->assertSame('complete', DB::table('v2_model_quality_run')->value('status'));
    }
}
