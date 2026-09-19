<?php

namespace App\Console\Commands;

use App\Services\PlanService;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Order;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

use Exception;

class CheckRenewal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动续费';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $now = time();
        User::query()
            ->where('auto_renewal', 1)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<', $now + 86400 * 2)
            ->chunkById(100, function ($users) {
                foreach ($users as $candidate) {
                    try {
                        DB::transaction(function () use ($candidate) {
                            $user = User::whereKey($candidate->id)->lockForUpdate()->first();
                            if (!$user || !$user->auto_renewal || !$user->plan_id || !$user->expired_at) {
                                return;
                            }
                            if ($user->expired_at <= time() || $user->expired_at >= time() + 86400 * 2) {
                                return;
                            }

                            $latestOrder = Order::where('user_id', $user->id)
                                ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
                                ->where('status', 3)
                                ->orderByDesc('created_at')
                                ->first();
                            if (!$latestOrder) {
                                throw new Exception('No valid order');
                            }

                            $latestPeriod = $latestOrder->period;
                            $plan = (new PlanService($user->plan_id))->plan;
                            if (!$plan || !$plan->renew) {
                                throw new Exception('This subscription cannot be renewed');
                            }
                            $price = (int)$plan[$latestPeriod];
                            if ($user->balance < $price) {
                                throw new Exception('No enough balance');
                            }

                            $order = new Order();
                            $order->user_id = $user->id;
                            $order->plan_id = $plan->id;
                            $order->period = $latestPeriod;
                            $order->trade_no = Helper::generateOrderNo();
                            $order->balance_amount = $price;
                            $order->total_amount = 0;
                            $order->type = 2;
                            $order->status = 3;

                            $user->balance -= $price;
                            $user->expired_at = $this->getTime($latestPeriod, $user->expired_at);
                            if (!$user->save() || !$order->save()) {
                                throw new Exception('自动续费失败');
                            }
                        }, 3);
                    } catch (\Throwable $e) {
                        if (!User::whereKey($candidate->id)->update(['auto_renewal' => 0])) {
                            info('用户自动续费失败,调整设置失败', [$e->getMessage(), $candidate->id]);
                        }
                    }
                }
            });
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }
}
