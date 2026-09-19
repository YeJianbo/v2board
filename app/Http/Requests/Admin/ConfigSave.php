<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfigSave extends FormRequest
{
    const RULES = [
        // invite & commission
        'invite_force' => '',
        'invite_commission' => 'integer|nullable',
        'invite_gen_limit' => 'integer|nullable',
        'invite_never_expire' => '',
        'commission_first_time_enable' => '',
        'commission_auto_check_enable' => '',
        'commission_withdraw_limit' => 'nullable|numeric',
        'commission_withdraw_method' => 'nullable|array',
        'withdraw_close_enable' => '',
        'commission_distribution_enable' => '',
        'commission_distribution_l1' => 'nullable|numeric',
        'commission_distribution_l2' => 'nullable|numeric',
        'commission_distribution_l3' => 'nullable|numeric',
        // site
        'logo' => 'nullable|url',
        'force_https' => '',
        'user_frontend_enable' => 'boolean',
        'homepage_mode' => 'in:monitor,user,closed',
        'stop_register' => '',
        'app_name' => '',
        'app_description' => '',
        'app_url' => 'nullable|url',
        'subscribe_url' => 'nullable',
        'try_out_enable' => '',
        'try_out_plan_id' => 'integer',
        'try_out_hour' => 'numeric',
        'tos_url' => 'nullable|url',
        'currency' => '',
        'currency_symbol' => '',
        'ticket_must_wait_reply' => '',
        // subscribe
        'plan_change_enable' => '',
        'reset_traffic_method' => 'in:0,1,2,3,4',
        'surplus_enable' => '',
        'new_order_event_id' => '',
        'renew_order_event_id' => '',
        'change_order_event_id' => '',
        'show_info_to_server_enable' => '',
        'show_protocol_to_server_enable' => '',
        'show_subscribe_method' => 'integer|in:0,1,2,3',
        'show_subscribe_expire' => 'integer|min:1|max:1440',
        'subscribe_path' => '',
        'ticket_reply_limit' => 'boolean',
        'ticket_active_subscription_required' => 'boolean',
        // server
        'server_token' => 'nullable|min:16',
        'server_pull_interval' => 'integer',
        'server_push_interval' => 'integer',
        'device_limit_mode' => 'integer',
        'server_ws_enable' => 'boolean',
        'server_ws_url' => 'nullable|url',
        // frontend
        'frontend_theme' => '',
        'frontend_theme_sidebar' => 'nullable|in:dark,light',
        'frontend_theme_header' => 'nullable|in:dark,light',
        'frontend_theme_color' => 'nullable|in:default,darkblue,black,green',
        'frontend_background_url' => 'nullable|url',
        // email
        'email_host' => '',
        'email_port' => '',
        'email_username' => '',
        'email_password' => '',
        'email_encryption' => '',
        'email_from_address' => '',
        'email_template' => 'nullable|string',
        'remind_mail_enable' => '',
        // telegram
        'telegram_bot_enable' => '',
        'telegram_bot_token' => '',
        'telegram_webhook_url' => 'nullable|url',
        'telegram_admin_chat_id' => 'nullable|string|max:500',
        'telegram_discuss_id' => '',
        'telegram_channel_id' => '',
        'telegram_discuss_link' => 'nullable|url',
        'telegram_machine_alert_enable' => 'boolean',
        'telegram_machine_status_alert_enable' => 'boolean',
        'telegram_machine_offline_seconds' => 'integer|min:180|max:86400',
        'telegram_machine_resource_alert_enable' => 'boolean',
        'telegram_machine_resource_consecutive' => 'integer|min:1|max:30',
        'telegram_machine_resource_duration_seconds' => 'integer|min:60|max:86400',
        'telegram_machine_cpu_alert_enable' => 'boolean',
        'telegram_machine_cpu_threshold' => 'integer|min:1|max:100',
        'telegram_machine_memory_alert_enable' => 'boolean',
        'telegram_machine_memory_threshold' => 'integer|min:1|max:100',
        'telegram_machine_disk_alert_enable' => 'boolean',
        'telegram_machine_disk_threshold' => 'integer|min:1|max:100',
        'telegram_machine_network_alert_enable' => 'boolean',
        'telegram_machine_network_mbps_threshold' => 'numeric|min:0|max:1000000',
        'telegram_machine_quality_alert_enable' => 'boolean',
        'telegram_machine_quality_latency_threshold' => 'numeric|min:1|max:60000',
        'telegram_machine_quality_loss_threshold' => 'numeric|min:1|max:100',
        'telegram_machine_quality_consecutive_count' => 'integer|min:1|max:30',
        'telegram_machine_quality_cooldown_seconds' => 'integer|min:60|max:86400',
        'telegram_user_traffic_alert_enable' => 'boolean',
        'telegram_user_traffic_threshold' => 'integer|min:50|max:100',
        // app
        'windows_version' => '',
        'windows_download_url' => '',
        'macos_version' => '',
        'macos_download_url' => '',
        'android_version' => '',
        'android_download_url' => '',
        // safe
        'email_whitelist_enable' => 'boolean',
        'email_whitelist_suffix' => 'nullable|array',
        'email_gmail_limit_enable' => 'boolean',
        'captcha_enable' => 'boolean',
        'captcha_type' => 'in:recaptcha,turnstile,recaptcha-v3',
        'recaptcha_enable' => 'boolean',
        'recaptcha_key' => '',
        'recaptcha_site_key' => '',
        'recaptcha_v3_secret_key' => '',
        'recaptcha_v3_site_key' => '',
        'recaptcha_v3_score_threshold' => 'numeric|min:0|max:1',
        'turnstile_secret_key' => '',
        'turnstile_site_key' => '',
        'email_verify' => 'bool',
        'safe_mode_enable' => 'boolean',
        'register_limit_by_ip_enable' => 'boolean',
        'register_limit_count' => 'integer',
        'register_limit_expire' => 'integer',
        'secure_path' => 'min:8|regex:/^[\w-]*$/',
        'frontend_user_path' => 'sometimes|required|string|min:3|max:64|regex:/^[A-Za-z0-9_-]+$/|different:secure_path|not_in:admin,api,assets,theme,storage,vendor,livewire,_debugbar',
        'public_status_enable' => 'boolean',
        'google_login_enable' => 'boolean',
        'google_client_id' => 'nullable|string',
        'google_client_secret' => 'nullable|string',
        'google_redirect_uri' => 'nullable|url',
        'password_limit_enable' => 'boolean',
        'password_limit_count' => 'integer',
        'password_limit_expire' => 'integer',
        'default_remind_expire' => 'boolean',
        'default_remind_traffic' => 'boolean',
        'subscribe_template_singbox' => 'nullable',
        'subscribe_template_clash' => 'nullable',
        'subscribe_template_clashmeta' => 'nullable',
        'subscribe_template_clashverge' => 'nullable',
        'subscribe_template_stash' => 'nullable',
        'subscribe_template_surge' => 'nullable',
        'subscribe_template_surfboard' => 'nullable',
        // backup
        'backup_enable' => 'boolean',
        'backup_type' => 'in:database,migration',
        'backup_storage' => 'in:local,google_drive,rclone',
        'backup_frequency' => 'in:daily,weekly',
        'backup_time' => ['regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
        'backup_local_path' => 'nullable|string|max:500',
        'backup_remote' => 'nullable|string|max:500',
        'backup_keep_days' => 'integer|min:1|max:3650',
        'backup_password' => 'nullable|string|max:500'
    ];
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return self::RULES;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (!$this->hasAny(['frontend_user_path', 'secure_path', 'subscribe_path'])) return;
            $user = (string) $this->input('frontend_user_path', config('v2board.frontend_user_path', 'user'));
            $admin = (string) $this->input('secure_path', config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))));
            $subscribe = explode('/', trim((string) $this->input('subscribe_path', config('v2board.subscribe_path', '')), '/'))[0];
            if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $user) || in_array(strtolower($user), ['admin','api','assets','theme','storage','vendor','livewire','_debugbar'], true) || $user === $admin || ($subscribe !== '' && $user === $subscribe)) {
                $validator->errors()->add('frontend_user_path', '用户入口须为3-64位字母、数字、下划线或连字符，且不能与后台或系统路由冲突');
            }
        });
    }

    public function messages()
    {
        // illiteracy prompt
        return [
            'app_url.url' => '站点URL格式不正确，必须携带http(s)://',
            'subscribe_url.url' => '订阅URL格式不正确，必须携带http(s)://',
            'server_token.min' => '通讯密钥长度必须大于16位',
            'tos_url.url' => '服务条款URL格式不正确，必须携带http(s)://',
            'telegram_webhook_url.url' => 'Telegram Webhook地址格式不正确，必须携带http(s)://',
            'telegram_discuss_link.url' => 'Telegram群组地址必须为URL格式，必须携带http(s)://',
            'logo.url' => 'LOGO URL格式不正确，必须携带https(s)://',
            'secure_path.min' => '后台路径长度最小为8位',
            'secure_path.regex' => '后台路径只能为字母或数字',
            'frontend_user_path.required' => '用户前台路径不能为空',
            'frontend_user_path.min' => '用户前台路径长度最小为3位',
            'frontend_user_path.regex' => '用户前台路径只能为字母、数字、下划线或连字符',
            'frontend_user_path.different' => '用户前台路径不能与后台路径相同',
            'frontend_user_path.not_in' => '用户前台路径不能使用系统保留名称',
            'captcha_type.in' => '人机验证类型只能选择 recaptcha、turnstile 或 recaptcha-v3',
            'recaptcha_v3_score_threshold.numeric' => 'reCAPTCHA v3 分数阈值必须为数字',
            'recaptcha_v3_score_threshold.min' => 'reCAPTCHA v3 分数阈值不能小于0',
            'recaptcha_v3_score_threshold.max' => 'reCAPTCHA v3 分数阈值不能大于1'
        ];
    }
}
