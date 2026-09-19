<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\User;
use App\Services\AuthService;
use App\Services\ServerService;
use App\Services\StatisticalService;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class DashboardDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'app.key' => 'dashboard-tests-not-a-real-secret',
        ]);
        DB::purge('sqlite');
        Cache::flush();
        $this->app->instance(StatisticalService::class, \Mockery::mock(StatisticalService::class));
        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->integer('plan_id')->nullable();
            $table->integer('t')->default(0);
            $table->integer('online_count')->nullable();
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->integer('u')->default(0);
            $table->integer('d')->default(0);
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            $table->string('token')->default('test-only-token');
            $table->string('uuid')->default('test-only-uuid');
            $table->string('password')->default('test-only-password');
        });
        DB::table('v2_plan')->insert(['id' => 1, 'name' => 'Test plan']);
    }

    private function details(array $params): array
    {
        return app(StatController::class)->getDetails(Request::create('/details', 'GET', $params));
    }

    public function test_online_details_use_traffic_time_and_match_device_totals(): void
    {
        $now = time();
        DB::table('v2_user')->insert([
            ['email' => 'recent@example.test', 't' => $now, 'online_count' => 3, 'plan_id' => 1],
            ['email' => 'older@example.test', 't' => $now - 590, 'online_count' => 0, 'plan_id' => null],
            ['email' => 'stale@example.test', 't' => $now - 610, 'online_count' => 99, 'plan_id' => null],
        ]);
        $result = $this->details(['metric' => 'online-users', 'page_size' => 1]);
        $this->assertSame(2, $result['total']);
        $this->assertSame(4, $result['meta']['online_devices']);
        $this->assertSame('recent@example.test', $result['data'][0]['email']);
        $this->assertSame('Test plan', $result['data'][0]['plan_name']);
        $this->assertSame(3, $result['data'][0]['device_count']);
        $this->assertArrayNotHasKey('token', $result['data'][0]);
        $this->assertArrayNotHasKey('uuid', $result['data'][0]);
        $this->assertArrayNotHasKey('password', $result['data'][0]);
        $second = $this->details(['metric' => 'online-devices', 'page_size' => 1, 'page' => 2]);
        $this->assertSame(1, $second['data'][0]['device_count']);
        $summary = (new ReflectionMethod(StatController::class, 'getOnlineUserSummary'))->invoke(app(StatController::class));
        $this->assertSame(['users' => 2, 'devices' => 4], $summary);
    }

    public function test_search_empty_results_page_clamping_and_all_users(): void
    {
        DB::table('v2_user')->insert([
            ['email' => 'literal%name@example.test', 't' => time()],
            ['email' => 'offline@example.test', 't' => 0],
        ]);
        $result = $this->details(['metric' => 'users', 'search' => '%', 'page' => 99]);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['current_page']);
        $all = $this->details(['metric' => 'users']);
        $this->assertSame(2, $all['total']);
        $this->assertSame(0, $all['data'][0]['device_count']);
        $empty = $this->details(['metric' => 'online-users', 'search' => 'offline']);
        $this->assertSame([], $empty['data']);
        $this->assertSame(0, $empty['total']);
        $this->assertSame(0, $empty['meta']['online_devices']);
    }

    public function test_legacy_schema_without_device_count_is_supported(): void
    {
        DB::statement('ALTER TABLE v2_user DROP COLUMN online_count');
        DB::table('v2_user')->insert(['email' => 'legacy@example.test', 't' => time()]);
        $result = $this->details(['metric' => 'online-devices']);
        $this->assertSame(1, $result['data'][0]['device_count']);
        $this->assertSame(1, $result['meta']['online_devices']);
    }

    public function test_node_details_keep_protocol_ids_distinct_and_inherit_parent_heartbeat(): void
    {
        $service = new ServerService();
        $nodes = array_fill_keys(['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'], []);
        $nodes['v2node'] = [
            ['id' => 7, 'name' => 'Parent', 'protocol' => 'anytls', 'sort' => 2, 'token' => 'must-not-leak'],
            ['id' => 8, 'name' => 'Child', 'parent_id' => 7, 'protocol' => 'anytls', 'sort' => 1],
        ];
        $nodes['vless'] = [['id' => 7, 'name' => 'Offline VLESS']];
        (new ReflectionProperty($service, 'availableServerCollections'))->setValue($service, $nodes);
        $this->app->instance(ServerService::class, $service);
        Cache::put(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', 7), time(), 60);
        $result = $this->details(['metric' => 'online-nodes']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(['v2node:8', 'v2node:7'], array_column($result['data'], 'key'));
        $this->assertArrayNotHasKey('token', $result['data'][1]);
        $this->assertSame(1, $this->details(['metric' => 'online-nodes', 'search' => 'Child'])['total']);
        $this->assertSame(['total' => 3, 'online' => 2], $service->getNodeStatusSummary());
    }

    public function test_invalid_metric_and_unbounded_page_size_are_rejected(): void
    {
        foreach ([['metric' => 'secrets'], ['metric' => 'users', 'page_size' => 101]] as $params) {
            try {
                $this->details($params);
                $this->fail('Expected validation error');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_details_route_denies_guests_and_regular_users(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => str_ends_with($route->uri(), '/stat/getDetails'));
        $this->assertNotNull($route);
        $this->assertContains('admin', $route->gatherMiddleware());
        $url = '/' . $route->uri() . '?metric=online-users';
        $this->getJson($url)->assertStatus(403);
        $user = User::create(['email' => 'regular@example.test']);
        $auth = (new AuthService($user))->generateAuthData(Request::create('/test'));
        $this->withHeader('Authorization', $auth['auth_data'])->getJson($url)->assertStatus(403);
    }
}
