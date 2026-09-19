<?php

namespace Tests\Unit;

use App\Protocols\ClashMeta;
use App\Protocols\ClashVerge;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClashProtocolResponseTest extends TestCase
{
    public function testMetaAndVergeReturnIndependentHttpResponses(): void
    {
        $this->app->instance('request', Request::create('/subscribe', 'GET', ['flag' => 'verge']));
        $user = [
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1024,
            'expired_at' => 0,
            'uuid' => '00000000-0000-4000-8000-000000000001',
        ];

        foreach ([ClashMeta::class, ClashVerge::class] as $protocolClass) {
            $response = (new $protocolClass($user, []))->handle();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('24', $response->headers->get('profile-update-interval'));
            $this->assertStringContainsString('proxy-groups:', $response->getContent());
        }
    }

    public function testMetaProxyGroupsPreserveLiteralEntriesAndExpandRegexOnce(): void
    {
        $protocol = new TestableClashMeta([], []);
        $groups = [
            [
                'name' => '台湾故障转移',
                'proxies' => ['DIRECT', '/^台湾/'],
            ],
            [
                'name' => '全部节点',
                'proxies' => ['DIRECT'],
            ],
            [
                'name' => '仅手工',
                'proxies' => ['DIRECT'],
                '__skip_auto_fill' => true,
            ],
            [
                'name' => '空组',
                'proxies' => [],
                '__skip_auto_fill' => true,
            ],
        ];

        $resolved = $protocol->resolveGroups($groups, [
            '台湾 01',
            '台湾 02',
            '香港 01',
            '台湾 01',
        ]);

        $this->assertSame(['DIRECT', '台湾 01', '台湾 02'], $resolved[0]['proxies']);
        $this->assertSame(['DIRECT', '台湾 01', '台湾 02', '香港 01'], $resolved[1]['proxies']);
        $this->assertSame(['DIRECT'], $resolved[2]['proxies']);
        $this->assertArrayNotHasKey('__skip_auto_fill', $resolved[2]);
        $this->assertCount(3, $resolved);
    }
}

class TestableClashMeta extends ClashMeta
{
    public function resolveGroups(array $groups, array $proxies): array
    {
        return $this->resolveProxyGroups($groups, $proxies);
    }
}
