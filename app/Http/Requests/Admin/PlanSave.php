<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlanSave extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'content' => 'nullable|string',
            'reset_traffic_method' => 'nullable|integer|in:0,1,2,3,4',
            'transfer_enable' => 'integer|required|min:1',
            'group_id' => 'required|integer',
            'speed_limit' => 'integer|nullable|min:0',
            'device_limit' => 'integer|nullable|min:0',
            'capacity_limit' => 'integer|nullable|min:0',
            'month_price' => 'nullable|integer|min:0',
            'quarter_price' => 'nullable|integer|min:0',
            'half_year_price' => 'nullable|integer|min:0',
            'year_price' => 'nullable|integer|min:0',
            'two_year_price' => 'nullable|integer|min:0',
            'three_year_price' => 'nullable|integer|min:0',
            'onetime_price' => 'nullable|integer|min:0',
            'reset_price' => 'nullable|integer|min:0',
            'show' => 'nullable|boolean',
            'renew' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => '套餐名称不能为空',
            'name.max' => '套餐名称不能超过 255 个字符',
            'transfer_enable.required' => '流量配额不能为空',
            'transfer_enable.integer' => '流量配额必须是整数',
            'transfer_enable.min' => '流量配额必须大于 0',
            'group_id.required' => '权限组不能为空',
            'group_id.integer' => '权限组ID必须是整数',
            'speed_limit.integer' => '速度限制必须是整数',
            'speed_limit.min' => '速度限制不能为负数',
            'device_limit.integer' => '设备限制必须是整数',
            'device_limit.min' => '设备限制不能为负数',
            'capacity_limit.integer' => '容量限制必须是整数',
            'capacity_limit.min' => '容量限制不能为负数',
            'month_price.integer' => '月付金额格式有误',
            'quarter_price.integer' => '季付金额格式有误',
            'half_year_price.integer' => '半年付金额格式有误',
            'year_price.integer' => '年付金额格式有误',
            'two_year_price.integer' => '两年付金额格式有误',
            'three_year_price.integer' => '三年付金额格式有误',
            'onetime_price.integer' => '一次性金额有误',
            'reset_price.integer' => '流量重置包金额有误',
            'reset_traffic_method.in' => '流量重置方式格式有误',
        ];
    }
}
