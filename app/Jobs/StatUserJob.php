<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\StatisticalService;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $recordAt = strtotime(date('Y-m-d'));
        $serverRate = $this->server['rate'] ?? '1.00';
        $service = app(StatisticalService::class);
        $service->setStartAt($recordAt);

        foreach ($this->data as $userId => $trafficData) {
            $u = (int) ($trafficData[0] ?? 0);
            $d = (int) ($trafficData[1] ?? 0);

            if ($u <= 0 && $d <= 0) {
                continue;
            }

            $service->statUser($serverRate, (int) $userId, $u, $d);
        }
    }
}
