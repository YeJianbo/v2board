<?php
namespace Tests\Unit;

use App\Models\Machine;
use PHPUnit\Framework\TestCase;

class MachineRelayRulesTest extends TestCase
{
    public function test_normal_and_double_encoded_rules_keep_ports_and_targets(): void
    {
        $rules = [['listen_port' => 30756, 'target_host' => '192.0.2.1', 'target_port' => 30756, 'protocols' => ['tcp', 'udp']]];
        foreach ([json_encode($rules), json_encode(json_encode($rules))] as $raw) {
            $machine = new Machine();
            $machine->setRawAttributes(['relay_rules' => $raw]);
            $this->assertSame($rules, $machine->relay_rules);
        }
        $machine->setRawAttributes(['relay_rules' => '[]']);
        $this->assertSame([], $machine->relay_rules);
    }
}
