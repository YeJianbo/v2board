<?php

namespace Tests\Unit;

use App\Models\SubscribeTemplate;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class SubscribeTemplateTest extends TestCase
{
    public function testDefaultTemplatesCanBeParsed(): void
    {
        $this->assertIsArray(SubscribeTemplate::parseJson('singbox'));
        $this->assertIsArray(SubscribeTemplate::parseYaml('clash'));
        $this->assertIsArray(SubscribeTemplate::parseYaml('clashmeta'));
        $this->assertIsArray(SubscribeTemplate::parseYaml('clashverge'));
        $this->assertIsArray(SubscribeTemplate::parseYaml('stash'));
    }

    public function testInvalidYamlIsRejectedBeforeItCanBreakSubscriptions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SubscribeTemplate::validateContent('clashverge', "proxy-groups:\n  - [");
    }

    public function testParsedTemplatesAreCachedByTheirContent(): void
    {
        $content = SubscribeTemplate::getContent('clashmeta');
        $cacheKey = 'subscribe_template:parsed:yaml:clashmeta:' . sha1($content);
        Cache::forget($cacheKey);

        $parsed = SubscribeTemplate::parseYaml('clashmeta');

        $this->assertTrue(Cache::has($cacheKey));
        $this->assertSame($parsed, Cache::get($cacheKey));
    }
}
