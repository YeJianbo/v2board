<?php

namespace Plugin\EventAudit;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('user.register.after', function (User $user): void {
            if ($this->getConfig('log_registration', true)) {
                $this->write('user.register', ['user_id' => $user->id]);
            }
        });

        $this->listen('user.login.after', function (User $user): void {
            if ($this->getConfig('log_login', true)) {
                $this->write('user.login', [
                    'user_id' => $user->id,
                    'ip' => request()->ip(),
                ]);
            }
        });

        $this->listen('admin.user.update.after', function (array $payload): void {
            if (!$this->getConfig('log_admin_changes', true)) {
                return;
            }

            $this->write('admin.user.update', [
                'user_id' => data_get($payload, 'user.id'),
                'fields' => array_keys((array) data_get($payload, 'params', [])),
            ]);
        });

        $this->listen('admin.user.destroy.after', function (array $payload): void {
            if ($this->getConfig('log_admin_changes', true)) {
                $this->write('admin.user.destroy', [
                    'user_id' => data_get($payload, 'user.id'),
                ]);
            }
        });
    }

    private function write(string $event, array $context): void
    {
        Log::info('plugin.event_audit', array_merge([
            'event' => $event,
            'plugin' => $this->getPluginCode(),
        ], $context));
    }
}
