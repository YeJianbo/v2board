<?php

namespace Tests\Unit;

use App\Models\Machine;
use PHPUnit\Framework\TestCase;

class MachineMaintenanceTest extends TestCase
{
    public function test_future_maintenance_deadline_is_active(): void
    {
        $machine = new Machine();
        $machine->setRawAttributes(['maintenance_until' => 2000]);

        $this->assertTrue($machine->isInMaintenance(1999));
    }

    public function test_elapsed_or_empty_maintenance_deadline_is_inactive(): void
    {
        $elapsed = new Machine();
        $elapsed->setRawAttributes(['maintenance_until' => 2000]);
        $empty = new Machine();
        $empty->setRawAttributes(['maintenance_until' => 0]);

        $this->assertFalse($elapsed->isInMaintenance(2000));
        $this->assertFalse($empty->isInMaintenance(1000));
    }
}
