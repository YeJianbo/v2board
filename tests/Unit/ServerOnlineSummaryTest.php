<?php

namespace Tests\Unit;

use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ServerOnlineSummaryTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::clearResolvedInstance('cache');
        parent::tearDown();
    }

    private function serviceWithNodes(array $nodes): ServerService
    {
        $collections = array_fill_keys(['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'], []);
        $service = new ServerService();
        (new ReflectionProperty($service, 'availableServerCollections'))->setValue($service, array_replace($collections, $nodes));
        return $service;
    }

    public function test_counts_heartbeats_and_inherits_parent_without_colliding_protocol_ids(): void
    {
        $cache = new Repository(new ArrayStore());
        Cache::swap($cache);
        $cache->put(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', 7), time(), 60);
        $service = $this->serviceWithNodes([
            'v2node' => [['id' => 7], ['id' => 8, 'parent_id' => 7], ['id' => 9]],
            'vless' => [['id' => 7]],
        ]);
        $this->assertSame(['total' => 4, 'online' => 2], $service->getNodeStatusSummary());
    }

    public function test_an_empty_status_cache_does_not_mean_every_node_is_online(): void
    {
        Cache::swap(new Repository(new ArrayStore()));
        $service = $this->serviceWithNodes(['hysteria' => [['id' => 1]], 'v2node' => [['id' => 2]]]);
        $this->assertSame(['total' => 2, 'online' => 0], $service->getNodeStatusSummary());
    }
}
