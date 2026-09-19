<?php

namespace App\Services;

class SubscriptionResponse
{
    public static function headers($user, string $appName, ?string $appUrl = null): array
    {
        $headers = [
            'subscription-userinfo' => "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}",
            'profile-update-interval' => '24',
            'profile-title' => 'base64:' . base64_encode($appName),
            'Content-Disposition' => "attachment;filename*=UTF-8''" . rawurlencode($appName),
        ];

        if ($appUrl) {
            $headers['profile-web-page-url'] = $appUrl;
            $headers['support-url'] = $appUrl;
        }

        return $headers;
    }
}
