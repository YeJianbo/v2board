<?php

namespace Tests\Unit;

use App\Contracts\PaymentInterface;
use App\Models\Plugin;
use App\Services\PaymentService;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Mockery;
use Tests\TestCase;

class PaymentServicePluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::reset();
    }

    public function test_enabled_payment_plugin_uses_existing_payment_service_pipeline(): void
    {
        $plugin = new TestPaymentPlugin('test_payment');
        $plugin->boot();

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('initializeEnabledPlugins')->zeroOrMoreTimes();
        $manager->shouldReceive('getEnabledPaymentPlugins')->once()->andReturn([$plugin]);
        $this->app->instance(PluginManager::class, $manager);

        $service = new PaymentService('TestPayment');

        $this->assertSame('测试字段', $service->form()['token']['label']);
        $this->assertContains('TestPayment', PaymentService::getAllPaymentMethodNames());
        $this->assertContains('EPay', PaymentService::getAllPaymentMethodNames());
    }

    public function test_plugin_type_scope_filters_payment_plugins(): void
    {
        $query = Plugin::query()->byType(Plugin::TYPE_PAYMENT);

        $this->assertStringContainsString('where `type` = ?', $query->toSql());
        $this->assertSame([Plugin::TYPE_PAYMENT], $query->getBindings());
    }
}

class TestPaymentPlugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            $methods['TestPayment'] = [
                'name' => '测试支付',
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];

            return $methods;
        });
    }

    public function form(): array
    {
        return ['token' => ['type' => 'string', 'label' => '测试字段']];
    }

    public function pay($order): array
    {
        return ['type' => 1, 'data' => 'https://example.test/pay'];
    }

    public function notify($params): array
    {
        return $params;
    }
}
