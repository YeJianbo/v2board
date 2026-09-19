<?php

namespace Tests\Unit;

use App\Services\ModelRelayBilling;
use PHPUnit\Framework\TestCase;

class ModelRelayBillingTest extends TestCase
{
    private array $prices = ['input' => '0.002', 'output' => '0.006', 'cache_read' => '0.0005', 'cache_write' => '0'];
    public function test_cache_hit_is_not_charged_again_as_regular_input(): void
    {
        $hit = ModelRelayBilling::calculate($this->prices, 214, 418, 192, 0);
        $this->assertSame('0.000002648000', $hit['cost']);
        $this->assertSame('confirmed', $hit['cost_status']);
        $miss = ModelRelayBilling::calculate($this->prices, 214, 418, 0, 0);
        $this->assertSame('0.000002936000', $miss['cost']);
    }
    public function test_unknown_fields_are_not_silently_reported_as_confirmed(): void
    {
        $this->assertSame('estimated', ModelRelayBilling::calculate($this->prices, 214, 418, null, 0)['cost_status']);
        $this->assertNull(ModelRelayBilling::calculate($this->prices, null, null, null, null)['cost']);
        $this->assertSame('inconsistent', ModelRelayBilling::calculate($this->prices, 100, 20, 90, 20)['cost_status']);
        $this->assertSame('unpriced', ModelRelayBilling::calculate(null, 100, 20, 0, 0)['cost_status']);
    }
}
