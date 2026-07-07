<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    protected $table = 'v2_mail_template';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const TEMPLATES = [
        'verify' => [
            'label' => '邮箱验证码',
            'required_vars' => ['name', 'code', 'url'],
            'optional_vars' => [],
        ],
        'notify' => [
            'label' => '站点通知',
            'required_vars' => ['name', 'content', 'url'],
            'optional_vars' => [],
        ],
        'remindExpire' => [
            'label' => '到期提醒',
            'required_vars' => ['name', 'url'],
            'optional_vars' => [],
        ],
        'remindTraffic' => [
            'label' => '流量提醒',
            'required_vars' => ['name', 'url'],
            'optional_vars' => [],
        ],
        'mailLogin' => [
            'label' => '邮件登录',
            'required_vars' => ['name', 'link', 'url'],
            'optional_vars' => [],
        ],
    ];

    public static function getMeta(?string $name): ?array
    {
        if (!$name) {
            return null;
        }

        return self::TEMPLATES[$name] ?? null;
    }

    public static function validateContent(string $name, string $content): array
    {
        $meta = self::getMeta($name);
        if (!$meta) {
            return ['模板不存在'];
        }

        $errors = [];
        foreach ($meta['required_vars'] as $var) {
            if (!str_contains($content, '{{' . $var . '}}')) {
                $errors[] = "缺少变量 {{$var}}";
            }
        }

        return $errors;
    }
}
