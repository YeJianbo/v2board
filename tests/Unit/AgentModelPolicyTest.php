<?php
namespace Tests\Unit;

use App\Services\AgentModelPolicy;
use App\Services\PanelAgentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentModelPolicyTest extends TestCase
{
    use \Tests\Concerns\FakesAgentTransport;

    protected function setUp(): void { parent::setUp(); $this->fakeAgentTransport(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function config(): array
    {
        $profiles = [];
        foreach (AgentModelPolicy::MODELS as $name => $model) $profiles[$name] = ['enabled' => true, 'base_url' => 'https://' . $name . '.example/v1', 'api_key' => $name . '-secret'];
        return ['enabled' => true, 'tools' => [], 'profiles' => $profiles];
    }
    public function test_time_boundaries_in_beijing_time(): void
    {
        foreach (['00:00:00' => true, '08:59:59' => true, '09:00:00' => false, '11:59:59' => false, '12:00:00' => true, '13:59:59' => true, '14:00:00' => false, '17:59:59' => false, '18:00:00' => true, '23:59:59' => true] as $time => $expected) {
            Carbon::setTestNow(Carbon::parse('2026-09-12 ' . $time, 'Asia/Shanghai'));
            $this->assertSame('gemini', AgentModelPolicy::schedule()['primary'], $time);
            $config = $this->config();
            $config['profiles']['deepseek'] = ['enabled' => true, 'base_url' => 'https://deepseek.example/v1', 'api_key' => 'legacy-secret'];
            $this->assertSame(['gemini-3.8-flash', 'grok-4.6'], array_column(AgentModelPolicy::candidates($config), 'model'));
        }
    }
    public function test_manual_selection_rejects_removed_deepseek(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 10:00', 'Asia/Shanghai'));
        $this->assertSame(['grok-4.6'], array_column(AgentModelPolicy::candidates($this->config() + ['model_choice' => 'grok']), 'model'));
        $this->assertSame(['gemini-3.8-flash'], array_column(AgentModelPolicy::candidates($this->config() + ['model_choice' => 'gemini']), 'model'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        AgentModelPolicy::candidates($this->config() + ['model_choice' => 'deepseek']);
    }
    public function test_primary_failure_falls_back_with_separate_credentials(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 10:00', 'Asia/Shanghai'));
        $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->with(true)->andReturn($this->config());
        Http::fake([
            'gemini.example/*' => Http::response([], 503),
            'grok.example/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => '需要先明确节点范围。']]]]),
        ]);
        $result = $agent->chat('重命名', Request::create('/'));
        $this->assertSame('grok-4.6', $result['model']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'gemini.example') && $r->hasHeader('Authorization', 'Bearer gemini-secret'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'grok.example') && $r->hasHeader('Authorization', 'Bearer grok-secret'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'deepseek'));
    }
    public function test_manual_failure_does_not_silently_change_model(): void
    {
        $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->with(true)->andReturn($this->config());
        Http::fake(['*' => Http::response([], 503)]);
        try { $agent->chat('测试', Request::create('/', 'POST', ['model_choice' => 'grok'])); $this->fail('Should fail'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(502, $e->getStatusCode()); }
        Http::assertSentCount(1);
    }
    public function test_cloudflare_rejection_is_reported_without_echoing_upstream_html(): void
    {
        $agent = \Mockery::mock(PanelAgentService::class)->makePartial();
        $agent->shouldReceive('settings')->with(true)->andReturn($this->config());
        Http::fake(['*' => Http::response('<title>Attention Required!</title>Sorry, you have been blocked PRIVATE-UPSTREAM-CONTENT', 403, ['Server' => 'cloudflare'])]);
        $request = Request::create('/', 'POST', ['message' => '测试', 'model_choice' => 'grok']);
        $response = (new \App\Http\Controllers\V2\Admin\AgentController())->chat($request, $agent);
        $this->assertSame(503, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Cloudflare', $body['message']);
        $this->assertStringContainsString('403', $body['message']);
        $this->assertStringNotContainsString('PRIVATE-UPSTREAM-CONTENT', $response->getContent());
        Http::assertSentCount(1);
    }
    public function test_profile_keys_are_independent_encrypted_and_preserved(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        $path = sys_get_temp_dir() . '/agent-policy-' . uniqid() . '.json';
        $agent = new class($path) extends PanelAgentService {
            private string $file;
            public function __construct(string $path) { $this->file = $path; }
            protected function path(): string { return $this->file; }
        };
        try {
            $custom = $this->config();
            $custom['profiles']['custom_test'] = ['model' => 'vendor/test-model', 'label' => 'Test model', 'enabled' => true, 'base_url' => 'https://custom.example/v1', 'api_key' => 'custom-secret'];
            $saved = $agent->save($custom);
            $this->assertSame('vendor/test-model', $saved['profiles']['custom_test']['model']);
            $this->assertArrayNotHasKey('api_key', $saved['profiles']['custom_test']);
            $this->assertStringNotContainsString('custom-secret', file_get_contents($path));
            $this->assertSame(['vendor/test-model'], array_column(AgentModelPolicy::candidates($agent->settings(true) + ['model_choice' => 'custom_test']), 'model'));
            $this->assertSame(['gemini-3.8-flash', 'grok-4.6'], array_column(AgentModelPolicy::candidates($agent->settings(true)), 'model'));
            $custom['profiles']['custom_test']['api_key'] = '';
            $custom['profiles']['custom_test']['protocol'] = 'anthropic';
            $agent->save($custom);
            $this->assertSame('custom-secret', $agent->settings(true)['profiles']['custom_test']['api_key']);
            $this->assertSame('anthropic', AgentModelPolicy::candidates($agent->settings(true) + ['model_choice' => 'custom_test'])[0]['protocol']);
            $invalid = $custom;
            $invalid['profiles']['custom_test']['protocol'] = 'invalid';
            try { $agent->save($invalid); $this->fail('Unknown protocol accepted'); }
            catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('profiles.custom_test.protocol', $e->errors()); }
            $legacy = json_decode(file_get_contents($path), true);
            $legacy['profiles']['deepseek'] = ['enabled' => true, 'api_key' => 'legacy-ciphertext'];
            file_put_contents($path, json_encode($legacy));
            $this->assertArrayNotHasKey('deepseek', $agent->settings()['profiles']);
            $this->assertArrayNotHasKey('deepseek', $agent->settings(true)['profiles']);
            foreach ($saved['profiles'] as $name => $p) {
                $this->assertArrayNotHasKey('api_key', $p);
                $this->assertStringNotContainsString($name . '-secret', file_get_contents($path));
            }
            $next = $this->config();
            foreach ($next['profiles'] as &$p) $p['api_key'] = '';
            unset($p);
            $agent->save($next);
            $this->assertArrayNotHasKey('custom_test', $agent->settings()['profiles']);
            $this->assertSame('grok-secret', $agent->settings(true)['profiles']['grok']['api_key']);
            $next['profiles']['grok']['base_url'] = 'https://new.example/v1';
            try { $agent->save($next); $this->fail('Key must not move silently'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        } finally { if (is_file($path)) unlink($path); }
    }
}
