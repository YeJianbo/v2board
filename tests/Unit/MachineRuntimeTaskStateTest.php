<?php
namespace Tests\Unit;

use App\Services\MachineRuntimeTaskState;
use PHPUnit\Framework\TestCase;

class MachineRuntimeTaskStateTest extends TestCase
{
    public function test_query_failure_does_not_change_service_health(): void
    {
        $state = MachineRuntimeTaskState::apply(['gost_status' => 'active'], ['action' => 'logs', 'status' => 'failed', 'message' => 'journalctl unavailable'], 123);
        $this->assertSame('active', $state['gost_status']);
        $this->assertArrayNotHasKey('runtime_health', $state);
        $this->assertArrayNotHasKey('runtime_error', $state);
        $this->assertSame('journalctl unavailable', $state['runtime_query_error']);
    }

    public function test_legacy_query_error_moves_without_masking_gost_failure(): void
    {
        $state = MachineRuntimeTaskState::separateLegacyQueryError(['gost_status' => 'failed', 'gost_error' => 'exit status 1', 'runtime_health' => 'error', 'runtime_error' => '读取运行日志失败: missing journalctl']);
        $this->assertArrayNotHasKey('runtime_error', $state);
        $this->assertSame('failed', $state['gost_status']);
        $this->assertSame('exit status 1', $state['gost_error']);
    }

    public function test_successful_query_does_not_clear_control_failure(): void
    {
        $state = MachineRuntimeTaskState::apply(['runtime_error' => 'restart failed', 'runtime_health' => 'error'], ['action' => 'status', 'status' => 'success', 'message' => 'ok'], 123);
        $this->assertSame('restart failed', $state['runtime_error']);
        $this->assertSame('error', $state['runtime_health']);
    }
}
