<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class RunPanelBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    private string $type;

    public function __construct(string $type)
    {
        $this->type = $type;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('panel:backup', [
            '--type' => $this->type,
        ]);
        if ($exitCode !== 0) {
            throw new RuntimeException('Panel backup failed: ' . trim(Artisan::output()));
        }
    }
}
