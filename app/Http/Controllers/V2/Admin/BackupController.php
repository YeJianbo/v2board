<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunPanelBackupJob;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function status()
    {
        $statusFile = storage_path('app/backup/status.json');
        $status = [];
        if (is_file($statusFile)) {
            $decoded = json_decode((string) file_get_contents($statusFile), true);
            $status = is_array($decoded) ? $decoded : [];
        }

        return $this->success([
            'enabled' => (bool) admin_setting('backup_enable', false),
            'rclone_available' => is_executable('/usr/bin/rclone') || is_executable('/usr/local/bin/rclone'),
            'status' => $status,
        ]);
    }

    public function run(Request $request)
    {
        $params = $request->validate([
            'type' => 'nullable|in:database,migration',
        ]);
        $type = (string) ($params['type'] ?? admin_setting('backup_type', 'database'));
        RunPanelBackupJob::dispatch($type);

        return $this->success([
            'queued' => true,
            'type' => $type,
        ]);
    }

}
