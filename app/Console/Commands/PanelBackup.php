<?php

namespace App\Console\Commands;

use App\Services\PanelGoogleDriveService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PanelBackup extends Command
{
    protected $signature = 'panel:backup
        {--type= : database or migration}
        {--remote= : rclone destination, for example gdrive:buncloud}
        {--keep-days= : local and remote retention days}';

    protected $description = 'Back up the panel database or create a complete migration package';

    public function handle(): int
    {
        $type = (string) ($this->option('type') ?: admin_setting('backup_type', 'database'));
        $remoteOption = $this->option('remote');
        $remote = (string) ($remoteOption ?? admin_setting('backup_remote', ''));
        $rcloneConfig = '';
        if ($remoteOption === null) {
            $storage = (string) admin_setting('backup_storage', '');
            if (!in_array($storage, ['local', 'google_drive', 'rclone'], true)) {
                $storage = trim($remote) !== '' ? 'rclone' : 'local';
            }

            if ($storage === 'local') {
                $remote = '';
            } elseif ($storage === 'google_drive') {
                $googleDrive = app(PanelGoogleDriveService::class);
                if (!$googleDrive->isAuthorized()) {
                    $this->error('Google Drive is selected but has not been authorized');
                    return 1;
                }
                $remote = $googleDrive->remoteDestination();
                $rcloneConfig = $googleDrive->configPath();
            }
        }
        $keepDays = (int) ($this->option('keep-days') ?: admin_setting('backup_keep_days', 14));
        $outputDir = trim((string) admin_setting('backup_local_path', ''));
        if ($outputDir === '') {
            $outputDir = storage_path('app/backups');
        }
        $password = (string) admin_setting('backup_password', '');

        if (!in_array($type, ['database', 'migration'], true)) {
            $this->error('backup_type must be database or migration');
            return 2;
        }
        if ($remote !== '' && $password === '') {
            $this->error('Remote backup requires a backup password so the uploaded archive is encrypted');
            return 2;
        }

        $script = base_path('deploy/backup/backup.sh');
        if (!is_file($script)) {
            $this->error('Backup script is missing: ' . $script);
            return 1;
        }

        $command = [
            'bash',
            $script,
            '--site-root',
            base_path(),
            '--type',
            $type,
            '--output-dir',
            $outputDir,
            '--keep-days',
            (string) max(1, $keepDays),
        ];
        if ($remote !== '') {
            $command[] = '--remote';
            $command[] = $remote;
        }
        if ($rcloneConfig !== '') {
            $command[] = '--rclone-config';
            $command[] = $rcloneConfig;
        }

        $process = new Process($command, base_path(), [
            'V2BOARD_BACKUP_PASSWORD' => $password,
        ]);
        $process->setTimeout(null);
        $process->run(function ($type, $buffer): void {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error(trim($process->getErrorOutput()) ?: 'Backup failed');
            return $process->getExitCode() ?: 1;
        }

        return 0;
    }
}
