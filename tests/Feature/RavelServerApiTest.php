<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Server\ServerController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Models\RavelCredential;
use App\Models\ServerV2node;
use App\Models\User;
use App\Protocols\Ravel;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use MessagePack\MessagePack;
use Tests\TestCase;

class RavelServerApiTest extends TestCase
{
    private const SERVER_TOKEN = 'ravel-server-token-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'), 'Ravel fixtures require an isolated SQLite database');
        Cache::flush();
        $this->createSchema();
        DB::table('v2_settings')->insert([
            'name' => 'server_token',
            'value' => self::SERVER_TOKEN,
        ]);
    }

    public function test_ravel_config_and_user_api_contract_are_private_no_store(): void
    {
        [$server, $user] = $this->fixtures('ravel');
        $this->assertSame(
            'authority.example.com',
            DB::table('v2_server_v2node')->where('id', $server->id)->value('ravel_authority')
        );
        $this->assertSame(1, DB::table('v2_user')->count());
        $configRequest = $this->serverRequest('config', $server->id);
        $config = (new ServerController($configRequest))->config($configRequest);
        $configBody = json_decode($config->getContent(), true);

        $this->assertSame('ravel', $configBody['protocol']);
        $this->assertSame('authority.example.com', $configBody['ravel']['authority']);
        $this->assertSame('/ravel/v1', $configBody['ravel']['path']);
        $this->assertSame('gw000001', $configBody['ravel']['gateway_group']);
        $this->assertSame(
            'https://origin.example/cover',
            $configBody['ravel']['masquerade']
        );
        $this->assertSame(65536, $configBody['ravel']['settings']['chunk_max']);
        $this->assertStringContainsString('private', $config->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $config->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('capability', $config->getContent());

        $userRequest = $this->serverRequest('user', $server->id);
        $users = (new ServerController($userRequest))->user($userRequest);
        $usersBody = json_decode($users->getContent(), true);
        $credentials = $usersBody['users'][0]['ravel_credentials'];

        $this->assertCount(1, $credentials);
        $this->assertSame($user->id, $usersBody['users'][0]['id']);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            $credentials[0]['capability_key']
        );
        $this->assertStringContainsString('private', $users->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $users->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(
            'capability_key_ciphertext',
            $users->getContent()
        );
    }

    public function test_non_ravel_server_api_does_not_attach_credentials(): void
    {
        [$server] = $this->fixtures('vmess');
        $configRequest = $this->serverRequest('config', $server->id);
        $config = (new ServerController($configRequest))->config($configRequest);
        $configBody = json_decode($config->getContent(), true);
        $this->assertArrayNotHasKey('ravel', $configBody);

        $userRequest = $this->serverRequest('user', $server->id);
        $users = (new ServerController($userRequest))->user($userRequest);
        $usersBody = json_decode($users->getContent(), true);

        $this->assertArrayNotHasKey(
            'ravel_credentials',
            $usersBody['users'][0]
        );
        $this->assertSame(0, RavelCredential::count());
    }

    public function test_legacy_uniproxy_user_api_attaches_ravel_credentials(): void
    {
        [$server, $user] = $this->fixtures('ravel');

        foreach ([false, true] as $msgpack) {
            $request = $this->uniProxyRequest($server->id, $msgpack);
            $response = (new UniProxyController($request))->user($request);
            $body = $msgpack
                ? MessagePack::unpack($response->getContent())
                : json_decode($response->getContent(), true);

            $this->assertSame($user->id, $body['users'][0]['id']);
            $this->assertCount(1, $body['users'][0]['ravel_credentials']);
            $this->assertMatchesRegularExpression(
                '/\A[0-9a-f]{64}\z/',
                $body['users'][0]['ravel_credentials'][0]['capability_key']
            );
            $this->assertStringContainsString(
                'private',
                (string) $response->headers->get('Cache-Control')
            );
            $this->assertStringContainsString(
                'no-store',
                (string) $response->headers->get('Cache-Control')
            );
            $this->assertNull($response->headers->get('ETag'));
        }
    }

    public function test_server_apis_accept_bearer_authentication(): void
    {
        [$server] = $this->fixtures('ravel');

        $v2Request = $this->serverRequest('config', $server->id, true);
        $v2Response = (new ServerController($v2Request))->config($v2Request);
        $this->assertSame(200, $v2Response->getStatusCode());

        $v1Request = $this->uniProxyRequest($server->id, false, true);
        $v1Response = (new UniProxyController($v1Request))->user($v1Request);
        $this->assertSame(200, $v1Response->getStatusCode());
    }

    public function test_admin_node_list_only_contains_credential_summary(): void
    {
        [$server] = $this->fixtures('ravel');
        $request = $this->serverRequest('user', $server->id);
        (new ServerController($request))->user($request);

        $nodes = (new ServerService())->getAllV2node();
        $json = json_encode($nodes);

        $this->assertSame(1, $nodes[0]['ravel_credential_summary']['total']);
        $this->assertSame(1, $nodes[0]['ravel_credential_summary']['active']);
        $this->assertStringNotContainsString('capability_key', $json);
        $this->assertStringNotContainsString('ciphertext', $json);
        $this->assertStringNotContainsString(
            RavelCredential::first()->getRawOriginal('capability_key_ciphertext'),
            $json
        );
    }

    public function test_versioned_ravel_json_subscription_is_private_no_store(): void
    {
        [$server, $user] = $this->fixtures('ravel');
        $servers = (new ServerService())->getAvailableV2node($user);
        $response = (new Ravel($user, $servers))->handle();
        $body = json_decode($response->getContent(), true);

        $this->assertSame('v2board.ravel.subscription', $body['schema']);
        $this->assertSame(1, $body['schema_version']);
        $this->assertSame('ravel', $body['nodes'][0]['type']);
        $this->assertNotEmpty(
            $body['nodes'][0]['credentials'][0]['capability_key']
        );
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('ravel://', $response->getContent());
    }

    private function serverRequest(
        string $action,
        int $serverId,
        bool $bearerOnly = false
    ): Request
    {
        $parameters = [
            'node_id' => $serverId,
        ];
        if (!$bearerOnly) {
            $parameters['token'] = self::SERVER_TOKEN;
        }
        $server = $bearerOnly
            ? ['HTTP_AUTHORIZATION' => 'Bearer ' . self::SERVER_TOKEN]
            : [];
        $request = Request::create(
            '/api/v2/server/' . $action,
            'GET',
            $parameters,
            [],
            [],
            $server
        );
        $this->app->instance('request', $request);

        return $request;
    }

    private function uniProxyRequest(
        int $serverId,
        bool $msgpack = false,
        bool $bearerOnly = false
    ): Request {
        $parameters = [
            'node_type' => 'v2node',
            'node_id' => $serverId,
        ];
        if (!$bearerOnly) {
            $parameters['token'] = self::SERVER_TOKEN;
        }
        $server = [];
        if ($msgpack) {
            $server['HTTP_X_RESPONSE_FORMAT'] = 'msgpack';
        }
        if ($bearerOnly) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;
        }
        $request = Request::create(
            '/api/v1/server/UniProxy/user',
            'GET',
            $parameters,
            [],
            [],
            $server
        );
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_subscription_credentials_do_not_enter_shared_node_cache(): void
    {
        [$server, $user] = $this->fixtures('ravel');
        $nodes = (new ServerService())->getAvailableV2node($user);
        $this->assertNotEmpty($nodes[0]['ravel_credentials']);
        $cached = Cache::get('servers_v2node');
        $this->assertNotNull($cached);
        $this->assertArrayNotHasKey('ravel_credentials', $cached->first()->toArray());
    }

    private function fixtures(string $protocol): array
    {
        $server = ServerV2node::create([
            'group_id' => [1],
            'route_id' => [],
            'name' => ucfirst($protocol) . ' Edge',
            'parent_id' => null,
            'host' => 'edge.example.com',
            'listen_ip' => '0.0.0.0',
            'port' => '443',
            'server_port' => 443,
            'tags' => [],
            'rate' => '1',
            'show' => 1,
            'sort' => 1,
            'protocol' => $protocol,
            'tls' => 1,
            'tls_settings' => ['server_name' => 'sni.example.com'],
            'network' => 'tcp',
            'network_settings' => [],
            'encryption_settings' => [],
            'zero_rtt_handshake' => 0,
            'up_mbps' => 0,
            'down_mbps' => 0,
            'padding_scheme' => [],
            'ravel_authority' => $protocol === 'ravel' ? 'authority.example.com' : null,
            'ravel_path' => $protocol === 'ravel' ? '/ravel/v1' : null,
            'ravel_gateway_group' => $protocol === 'ravel' ? 'gw000001' : null,
            'ravel_masquerade' => $protocol === 'ravel'
                ? 'https://origin.example/cover'
                : null,
            'ravel_settings' => $protocol === 'ravel' ? [
                'chunk_max' => 65536,
                'max_message' => 1048576,
            ] : [],
        ]);
        $user = User::create([
            'email' => $protocol . '@example.com',
            'uuid' => 'user-uuid-' . $protocol,
            'group_id' => 1,
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1073741824,
            'expired_at' => time() + 2592000,
            'banned' => 0,
        ]);

        return [$server, $user];
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();
        foreach (['vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria', 'tuic', 'anytls'] as $protocol) {
            Schema::create('v2_server_' . $protocol, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('sort')->nullable();
            });
        }
        Schema::create('v2_settings', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('uuid')->nullable();
            $table->integer('group_id')->nullable();
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('expired_at')->nullable();
            $table->boolean('banned')->default(false);
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_server_v2node', function (Blueprint $table) {
            $table->increments('id');
            $table->text('group_id');
            $table->text('route_id')->nullable();
            $table->string('name');
            $table->integer('parent_id')->nullable();
            $table->string('host');
            $table->string('listen_ip')->default('0.0.0.0');
            $table->string('port');
            $table->integer('server_port');
            $table->text('tags')->nullable();
            $table->string('rate')->default('1');
            $table->boolean('show')->default(true);
            $table->integer('sort')->nullable();
            $table->string('protocol');
            $table->boolean('tls')->default(false);
            $table->text('tls_settings')->nullable();
            $table->string('flow')->nullable();
            $table->string('network')->default('tcp');
            $table->text('network_settings')->nullable();
            $table->string('encryption')->nullable();
            $table->text('encryption_settings')->nullable();
            $table->boolean('disable_sni')->default(false);
            $table->string('udp_relay_mode')->nullable();
            $table->boolean('zero_rtt_handshake')->default(false);
            $table->string('congestion_control')->nullable();
            $table->string('cipher')->nullable();
            $table->integer('up_mbps')->default(0);
            $table->integer('down_mbps')->default(0);
            $table->string('obfs')->nullable();
            $table->string('obfs_password')->nullable();
            $table->text('padding_scheme')->nullable();
            $table->string('ravel_authority')->nullable();
            $table->string('ravel_path', 2048)->nullable();
            $table->char('ravel_gateway_group', 8)->nullable();
            $table->string('ravel_masquerade', 2048)->nullable();
            $table->text('ravel_settings')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_ravel_credential', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('user_id');
            $table->char('credential_id', 32)->unique();
            $table->text('capability_key_ciphertext');
            $table->unsignedInteger('key_version');
            $table->unsignedBigInteger('policy_id')->default(0);
            $table->char('gateway_group', 8);
            $table->unsignedBigInteger('not_before');
            $table->unsignedBigInteger('not_after');
            $table->unsignedBigInteger('revoked_at')->nullable();
            $table->unsignedBigInteger('superseded_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['server_id', 'user_id', 'key_version']);
        });
    }
}
