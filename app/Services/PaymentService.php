<?php

namespace App\Services;


use App\Models\Payment;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;
    protected PluginManager $pluginManager;

    public static function getAllPaymentMethodNames(): array
    {
        $paymentPath = app_path('Payments');
        $legacyMethods = File::isDirectory($paymentPath)
            ? collect(File::files($paymentPath))
                ->filter(function ($file) {
                    return $file->getExtension() === 'php';
                })
                ->map(function ($file) {
                    return Str::before($file->getFilename(), '.php');
                })
                ->values()
                ->all()
            : [];

        $pluginManager = app(PluginManager::class);
        $pluginManager->initializeEnabledPlugins();
        $pluginMethods = array_keys(HookManager::filter('available_payment_methods', []));

        return array_values(array_unique(array_merge($legacyMethods, $pluginMethods)));
    }

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->pluginManager = app(PluginManager::class);
        $this->class = '\\App\\Payments\\' . $this->method;
        if ($id) {
            $payment = Payment::find($id);
            if (!$payment) abort(500, 'gate is not found');
            $payment = $payment->toArray();
        }
        if ($uuid) {
            $payment = Payment::where('uuid', $uuid)->first();
            if (!$payment) abort(500, 'gate is not found');
            $payment = $payment->toArray();
        }
        $this->config = [];
        if (isset($payment)) {
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        }

        $pluginMethods = $this->getAvailablePaymentMethods();
        if (isset($pluginMethods[$this->method]['plugin_code'])) {
            $pluginCode = $pluginMethods[$this->method]['plugin_code'];
            foreach ($this->pluginManager->getEnabledPaymentPlugins() as $plugin) {
                if ($plugin->getPluginCode() === $pluginCode) {
                    $plugin->setConfig($this->config);
                    $this->payment = $plugin;
                    return;
                }
            }
        }

        if (!class_exists($this->class)) {
            abort(500, 'gate is not found');
        }
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable']) abort(500, 'gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }

    public function getAvailablePaymentMethods(): array
    {
        $this->pluginManager->initializeEnabledPlugins();

        return HookManager::filter('available_payment_methods', []);
    }
}
