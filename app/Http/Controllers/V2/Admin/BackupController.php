<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunPanelBackupJob;
use App\Services\PanelGoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class BackupController extends Controller
{
    public function status(Request $request, PanelGoogleDriveService $googleDrive)
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
            'google_drive' => $googleDrive->status($request),
        ]);
    }

    public function run(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $params = $request->validate([
            'type' => 'nullable|in:database,migration',
        ]);
        $type = (string) ($params['type'] ?? admin_setting('backup_type', 'database'));
        if (admin_setting('backup_storage', '') === 'google_drive' && !$googleDrive->isAuthorized()) {
            throw ValidationException::withMessages([
                'storage' => ['Google Drive 尚未授权，不能启动远端备份'],
            ]);
        }
        RunPanelBackupJob::dispatch($type);

        return $this->success([
            'queued' => true,
            'type' => $type,
        ]);
    }

    public function googleStatus(Request $request, PanelGoogleDriveService $googleDrive)
    {
        return $this->success($googleDrive->status($request));
    }

    public function googleConfigure(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $params = $this->validateGoogleConfig($request);
        try {
            $googleDrive->configure(
                (string) $params['client_id'],
                (string) ($params['client_secret'] ?? ''),
                (string) $params['folder']
            );
        } catch (Throwable $error) {
            throw ValidationException::withMessages([
                'google_drive' => [$error->getMessage()],
            ]);
        }

        return $this->success($googleDrive->status($request));
    }

    public function googleAuthorize(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $params = $this->validateGoogleConfig($request);
        try {
            $googleDrive->configure(
                (string) $params['client_id'],
                (string) ($params['client_secret'] ?? ''),
                (string) $params['folder']
            );

            return $this->success($googleDrive->createAuthorizationUrl($request));
        } catch (Throwable $error) {
            throw ValidationException::withMessages([
                'google_drive' => [$error->getMessage()],
            ]);
        }
    }

    public function googleTest(Request $request, PanelGoogleDriveService $googleDrive)
    {
        try {
            return $this->success($googleDrive->testConnection());
        } catch (Throwable $error) {
            throw ValidationException::withMessages([
                'google_drive' => [$error->getMessage()],
            ]);
        }
    }

    public function googleDisconnect(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $googleDrive->disconnect();
        return $this->success($googleDrive->status($request));
    }

    public function googleCallback(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $result = $googleDrive->completeAuthorization($request);
        $query = http_build_query([
            'google_drive' => $result['success'] ? 'authorized' : 'error',
        ]);
        $separator = strpos($result['return_url'], '?') === false ? '?' : '&';

        return redirect()->away($result['return_url'] . $separator . $query);
    }

    private function validateGoogleConfig(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'client_secret' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'folder' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ]);
    }

}
