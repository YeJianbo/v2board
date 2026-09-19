<?php

namespace Tests\Unit;

use App\Protocols\Singbox\Singbox;
use App\Protocols\ClashMeta;
use App\Protocols\Ravel;
use App\Support\RavelSubscription;
use Illuminate\Http\Request;
use Tests\TestCase;

class RavelSubscriptionTest extends TestCase
{
    public function test_server_name_fallback_excludes_authority_port(): void
    {
        $server = $this->ravelServer();
        $server['tls_settings'] = ['server_name' => ''];
        unset($server['server_name']);
        $server['ravel_authority'] = 'gateway.example.com:8443';
        $this->assertSame('gateway.example.com', RavelSubscription::mihomo($server)['sni']);
        $this->assertSame('gateway.example.com', RavelSubscription::singbox($server)['tls']['server_name']);
    }
    public function test_mihomo_fields_follow_ravel_contract(): void
    {
        $proxy = RavelSubscription::mihomo($this->ravelServer());

        $this->assertSame('ravel', $proxy['type']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $proxy['credential-id']);
        $this->assertSame(str_repeat('a', 64), $proxy['capability-key']);
        $this->assertSame(3, $proxy['key-version']);
        $this->assertSame('gw000001', $proxy['gateway-group']);
        $this->assertArrayNotHasKey('policy-id', $proxy);
        $this->assertArrayNotHasKey('capability_key', $proxy);
    }

    public function test_mihomo_subscription_contains_ravel_and_is_private_no_store(): void
    {
        $request = Request::create('/subscribe', 'GET', ['flag' => 'mihomo']);
        $this->app->instance('request', $request);
        $response = (new ClashMeta($this->user(), [$this->ravelServer()]))->handle();
        $content = $response->getContent();

        $this->assertStringContainsString('type: ravel', $content);
        $this->assertStringContainsString('credential-id:', $content);
        $this->assertStringContainsString('capability-key:', $content);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_singbox_hides_ravel_without_explicit_capability(): void
    {
        $builder = new InspectableSingbox($this->user(), [$this->ravelServer()]);

        $this->assertSame([], $builder->proxies());
    }

    public function test_singbox_emits_snake_case_ravel_schema_v1(): void
    {
        $builder = new InspectableSingbox(
            $this->user(),
            [$this->ravelServer()],
            ['supports_ravel_schema_v1' => true]
        );
        $proxies = $builder->proxies();

        $this->assertCount(1, $proxies);
        $this->assertSame('ravel', $proxies[0]['type']);
        $this->assertSame(str_repeat('a', 64), $proxies[0]['capability_key']);
        $this->assertSame(443, $proxies[0]['server_port']);
        $this->assertArrayNotHasKey('capability-key', $proxies[0]);
    }

    public function test_self_client_subscription_emits_only_latest_credential(): void
    {
        $server = $this->ravelServer();
        $older = $server['ravel_credentials'][0];
        $older['credential_id'] = str_repeat('1', 32);
        $older['capability_key'] = str_repeat('b', 64);
        $older['key_version'] = 2;
        $server['ravel_credentials'][] = $older;

        $response = (new Ravel($this->user(), [$server]))->handle();
        $body = json_decode($response->getContent(), true);

        $this->assertCount(1, $body['nodes'][0]['credentials']);
        $this->assertSame(3, $body['nodes'][0]['credentials'][0]['key_version']);
    }

    public function test_ravel_singbox_response_is_private_no_store(): void
    {
        $builder = new InspectableSingbox(
            $this->user(),
            [$this->ravelServer()],
            ['supports_ravel_schema_v1' => true]
        );
        $response = $builder->handle();

        $this->assertStringContainsString(
            'private',
            (string) $response->headers->get('Cache-Control')
        );
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
    }

    public function test_non_ravel_singbox_regression(): void
    {
        $server = [
            'type' => 'v2node',
            'protocol' => 'shadowsocks',
            'name' => 'Legacy SS',
            'host' => '127.0.0.1',
            'port' => 8388,
            'cipher' => 'aes-128-gcm',
            'created_at' => time(),
            'network' => 'tcp',
            'network_settings' => [],
        ];
        $builder = new InspectableSingbox($this->user(), [$server]);
        $proxies = $builder->proxies();

        $this->assertCount(1, $proxies);
        $this->assertSame('shadowsocks', $proxies[0]['type']);
        $this->assertSame('user-uuid', $proxies[0]['password']);
    }

    private function ravelServer(): array
    {
        return [
            'type' => 'v2node',
            'protocol' => 'ravel',
            'name' => 'Ravel Edge',
            'host' => 'edge.example.com',
            'port' => 443,
            'tls_settings' => ['server_name' => 'sni.example.com'],
            'ravel_authority' => 'authority.example.com',
            'ravel_path' => '/ravel',
            'ravel_gateway_group' => 'gw000001',
            'ravel_credentials' => [[
                'credential_id' => '0123456789abcdef0123456789abcdef',
                'capability_key' => str_repeat('a', 64),
                'key_version' => 3,
                'policy_id' => 0,
                'gateway_group' => 'gw000001',
                'not_before' => 100,
                'not_after' => 200,
            ]],
        ];
    }

    private function user(): array
    {
        return [
            'uuid' => 'user-uuid',
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1024,
            'expired_at' => time() + 3600,
        ];
    }
}

class InspectableSingbox extends Singbox
{
    public function proxies(): array
    {
        return $this->buildProxies();
    }
}
