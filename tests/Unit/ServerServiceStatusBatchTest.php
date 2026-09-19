<?php

namespace Tests\Unit;

use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ServerServiceStatusBatchTest extends TestCase
{
    public function testProtocolCollectionsAreLoadedWithOneBulkCacheReadPerService(): void
    {
        $collections = [
            'servers_vless' => collect([['id' => 1]]),
            'servers_vmess' => collect([['id' => 2]]),
            'servers_trojan' => collect([['id' => 3]]),
            'servers_tuic' => collect([4 => ['id' => 4]]),
            'servers_hysteria' => collect([5 => ['id' => 5]]),
            'servers_shadowsocks' => collect([6 => ['id' => 6]]),
            'servers_anytls' => collect([7 => ['id' => 7]]),
            'servers_v2node' => collect([8 => ['id' => 8]]),
        ];

        Cache::shouldReceive('many')
            ->once()
            ->with(array_keys($collections))
            ->andReturn($collections);
        Cache::shouldReceive('put')->never();

        $method = new \ReflectionMethod(ServerService::class, 'availableServerCollection');
        $method->setAccessible(true);
        $service = new ServerService();

        $this->assertSame($collections['servers_vless'], $method->invoke($service, 'vless'));
        $this->assertSame($collections['servers_anytls'], $method->invoke($service, 'anytls'));
    }

    public function testServerStatusesUseOneBulkCacheReadAndMergeParentState(): void
    {
        $servers = [
            ['type' => 'vless', 'id' => 1, 'parent_id' => 10],
            ['type' => 'vless', 'id' => 2, 'parent_id' => 10],
            ['type' => 'trojan', 'id' => 3, 'parent_id' => null],
        ];
        $values = [
            CacheKey::get('SERVER_VLESS_ONLINE_USER', 1) => 2,
            CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', 1) => 100,
            CacheKey::get('SERVER_VLESS_LAST_PUSH_AT', 1) => 80,
            CacheKey::get('SERVER_VLESS_ONLINE_USER', 2) => 1,
            CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', 2) => 90,
            CacheKey::get('SERVER_VLESS_LAST_PUSH_AT', 2) => 110,
            CacheKey::get('SERVER_VLESS_ONLINE_USER', 10) => 4,
            CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', 10) => 120,
            CacheKey::get('SERVER_VLESS_LAST_PUSH_AT', 10) => 70,
            CacheKey::get('SERVER_TROJAN_ONLINE_USER', 3) => 3,
            CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', 3) => 60,
            CacheKey::get('SERVER_TROJAN_LAST_PUSH_AT', 3) => 75,
        ];

        Cache::shouldReceive('many')
            ->once()
            ->with(Mockery::on(function (array $keys): bool {
                return count($keys) === count(array_unique($keys));
            }))
            ->andReturn($values);

        $method = new \ReflectionMethod(ServerService::class, 'serverStatuses');
        $method->setAccessible(true);
        $statuses = $method->invoke(new ServerService(), $servers);

        $this->assertSame([
            'online' => 4,
            'last_check_at' => 120,
            'last_push_at' => 80,
        ], $statuses[0]);
        $this->assertSame([
            'online' => 4,
            'last_check_at' => 120,
            'last_push_at' => 110,
        ], $statuses[1]);
        $this->assertSame([
            'online' => 3,
            'last_check_at' => 60,
            'last_push_at' => 75,
        ], $statuses[2]);
    }
}
