<?php

namespace App\Http\Requests\Admin;

use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'id' => 'required|integer',
            'email' => 'email:strict',
            'password' => 'nullable|min:8',
            'transfer_enable' => 'integer|min:0|max:9223372036854775807',
            'expired_at' => 'nullable|integer',
            'banned' => 'bool',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
            'u' => 'integer|min:0|max:9223372036854775807',
            'd' => 'integer|min:0|max:9223372036854775807',
            'balance' => 'numeric',
            'commission_type' => 'integer',
            'commission_balance' => 'numeric',
            'remarks' => 'nullable',
            'speed_limit' => 'nullable|integer',
            'device_limit' => 'nullable|integer',
            'invite_user_email' => 'nullable|email:strict'
        ];

        return HookManager::filter('admin.user.update.rules', $rules, $this);
    }

    public function messages()
    {
        $messages = [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'transfer_enable.integer' => '流量额度必须为有效的整数字节数，不能超出存储上限',
            'transfer_enable.min' => '流量额度不能小于0',
            'transfer_enable.max' => '流量额度超出存储上限，请检查输入单位',
            'u.min' => '已用上行不能小于0',
            'u.max' => '已用上行超出存储上限',
            'd.min' => '已用下行不能小于0',
            'd.max' => '已用下行超出存储上限',
            'expired_at.integer' => '到期时间格式不正确',
            'banned.in' => '是否封禁格式不正确',
            'is_admin.required' => '是否管理员不能为空',
            'is_admin.in' => '是否管理员格式不正确',
            'is_staff.required' => '是否员工不能为空',
            'is_staff.in' => '是否员工格式不正确',
            'plan_id.integer' => '订阅计划格式不正确',
            'commission_rate.integer' => '推荐返利比例格式不正确',
            'commission_rate.nullable' => '推荐返利比例格式不正确',
            'commission_rate.min' => '推荐返利比例最小为0',
            'commission_rate.max' => '推荐返利比例最大为100',
            'discount.integer' => '专属折扣比例格式不正确',
            'discount.nullable' => '专属折扣比例格式不正确',
            'discount.min' => '专属折扣比例最小为0',
            'discount.max' => '专属折扣比例最大为100',
            'u.integer' => '上行流量格式不正确',
            'd.integer' => '下行流量格式不正确',
            'balance.integer' => '余额格式不正确',
            'commission_balance.integer' => '佣金格式不正确',
            'password.min' => '密码长度最小8位',
            'speed_limit.integer' => '限速格式不正确',
            'device_limit.integer' => '设备数量格式不正确'
        ];

        return HookManager::filter('admin.user.update.messages', $messages, $this);
    }
}
