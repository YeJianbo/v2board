<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\User\MachineMonitorController;
use Tests\TestCase;

class UserMachineMonitorTest extends TestCase
{
    public function test_permission_groups_visibility_and_parent_machine_are_respected(): void
    {
        $nodes = [
            ['id'=>1, 'show'=>0, 'group_id'=>[9], 'machine_id'=>10],
            ['id'=>2, 'show'=>1, 'group_id'=>[2], 'parent_id'=>1, 'relay_machine_id'=>20],
            ['id'=>3, 'show'=>1, 'group_id'=>[9], 'machine_id'=>30],
            ['id'=>4, 'show'=>0, 'group_id'=>[2], 'machine_id'=>40],
            ['id'=>5, 'show'=>1, 'group_id'=>[2], 'machine_id'=>10, 'entry_machine_id'=>50],
        ];
        $this->assertSame([10,20,50], MachineMonitorController::allowedMachineIds($nodes,2));
        $this->assertSame([30], MachineMonitorController::allowedMachineIds($nodes,9));
        $this->assertSame([], MachineMonitorController::allowedMachineIds($nodes,0));
        $this->assertSame([], MachineMonitorController::allowedMachineIds($nodes,3));
    }

    public function test_monitor_requires_authentication(): void
    {
        $this->getJson('/api/v1/user/machine/monitor')->assertForbidden();
    }
}
