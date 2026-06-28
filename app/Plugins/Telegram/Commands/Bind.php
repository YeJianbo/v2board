<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Bind extends Telegram {
    public $command = '/bind';
    public $description = '将Telegram账号绑定到网站';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (!isset($message->args[0])) {
            abort(500, '参数有误，请携带订阅地址发送');
        }
        $subscribeUrl = parse_url($message->args[0]);
        parse_str(is_array($subscribeUrl) ? ($subscribeUrl['query'] ?? '') : '', $query);
        $token = $query['token'] ?? '';
        if (is_array($token)) {
            $token = '';
        }
        if (!$token) {
            abort(500, '订阅地址无效');
        }
        $token = Helper::resolveSubscribeToken((string)$token, false);
        if (!$token) {
            abort(403, 'token is error');
        }

        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, '用户不存在');
        }
        if ($user->telegram_id) {
            abort(500, '该账号已经绑定了Telegram账号');
        }
        $user->telegram_id = $message->chat_id;
        if (!$user->save()) {
            abort(500, '设置失败');
        }
        $telegramService = $this->telegramService;
        $telegramService->sendMessage($message->chat_id, '绑定成功');
    }
}
