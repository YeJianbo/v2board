<?php

namespace Tests\Unit;

use App\Protocols\ClashMeta;
use App\Protocols\ClashVerge;
use App\Services\ClientProtocolResolver;
use PHPUnit\Framework\TestCase;

class ClientProtocolResolverTest extends TestCase
{
    public function testItResolvesSpecificClashClientsBeforeGenericClash(): void
    {
        $resolver = new ClientProtocolResolver();

        $this->assertSame(ClashVerge::class, $resolver->resolve('Clash Verge Rev 2.3.0'));
        $this->assertSame(ClashMeta::class, $resolver->resolve('clash-meta/1.19.3'));
    }

    public function testItRecognizesSingboxAtTheBeginningOfUserAgent(): void
    {
        $resolver = new ClientProtocolResolver();

        $this->assertTrue($resolver->isSingbox('sing-box 1.13.0'));
        $this->assertSame('1.13.0', $resolver->singboxVersion('sing-box 1.13.0'));
        $this->assertTrue(version_compare($resolver->singboxVersion('sing-box 1.9.0'), '1.12.0', '<'));
    }
}
