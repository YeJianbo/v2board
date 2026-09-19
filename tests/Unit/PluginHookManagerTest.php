<?php

namespace Tests\Unit;

use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class PluginHookManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::reset();
    }

    public function test_action_failure_does_not_block_later_callbacks(): void
    {
        Log::shouldReceive('error')->once();
        $called = false;

        HookManager::register('test.isolation', function (): void {
            throw new RuntimeException('expected test failure');
        }, 10);
        HookManager::register('test.isolation', function () use (&$called): void {
            $called = true;
        }, 20);

        HookManager::call('test.isolation');

        $this->assertTrue($called);
    }

    public function test_removing_one_callback_keeps_other_callbacks(): void
    {
        $removedCalls = 0;
        $remainingCalls = 0;
        $removed = function () use (&$removedCalls): void {
            $removedCalls++;
        };
        $remaining = function () use (&$remainingCalls): void {
            $remainingCalls++;
        };

        HookManager::register('test.remove', $removed);
        HookManager::register('test.remove', $remaining);
        HookManager::remove('test.remove', $removed);
        HookManager::call('test.remove');

        $this->assertSame(0, $removedCalls);
        $this->assertSame(1, $remainingCalls);
    }
}
