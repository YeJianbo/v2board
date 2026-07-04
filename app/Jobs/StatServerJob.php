<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\StatisticalService;

class StatServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    //protected $u;
    //protected $d;
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
    public function __construct(array $data,array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        //$this->u = $u;
        //$this->d = $d;
        $this->data = $data;
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
        $serverId = (int) ($this->server['id'] ?? 0);
        $serverType = strtolower((string) $this->protocol);

        if ($serverId <= 0 || $serverType === '') {
            return;
        }

        $u = 0;
        $d = 0;
        foreach ($this->data as $trafficData) {
            $u += (int) ($trafficData[0] ?? 0);
            $d += (int) ($trafficData[1] ?? 0);
        }

        if ($u <= 0 && $d <= 0) {
            return;
        }

        $service = app(StatisticalService::class);
        $service->setStartAt($recordAt);
        $service->statServer($serverId, $serverType, $u, $d);
    }
}
