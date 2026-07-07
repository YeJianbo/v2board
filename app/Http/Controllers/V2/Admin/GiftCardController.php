<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Giftcard;
use App\Models\Plan;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftCardController extends Controller
{
    private function formatGiftcard(Giftcard $giftcard): array
    {
        $usedUserIds = (array) ($giftcard->used_user_ids ?: []);
        $plan = $giftcard->plan_id ? Plan::find($giftcard->plan_id) : null;
        $type = (int) $giftcard->type;

        return [
            'id' => (int) $giftcard->id,
            'template_id' => (int) $giftcard->id,
            'name' => (string) $giftcard->name,
            'plan_id' => $giftcard->plan_id ? (int) $giftcard->plan_id : null,
            'plan_name' => $plan?->name ?? '',
            'period' => $this->formatPeriod($giftcard),
            'price' => (int) ($type === 1 ? $giftcard->value : 0),
            'code' => (string) $giftcard->code,
            'status' => count($usedUserIds) > 0 ? 1 : 0,
            'used_at' => null,
            'used_by_user_id' => $usedUserIds[0] ?? null,
            'codes_count' => 1,
            'used_count' => count($usedUserIds) > 0 ? 1 : 0,
            'created_at' => (int) $giftcard->created_at,
            'updated_at' => (int) $giftcard->updated_at,
        ];
    }

    private function formatPeriod(Giftcard $giftcard): string
    {
        $type = (int) $giftcard->type;
        if ($type === 2 || $type === 5) {
            return ((int) $giftcard->value) . '天';
        }
        if ($type === 3) {
            return ((int) $giftcard->value) . 'GB';
        }
        if ($type === 4) {
            return '重置';
        }
        return '金额';
    }

    private function copyGiftcard(Giftcard $source): Giftcard
    {
        return Giftcard::create([
            'code' => Helper::randomChar(16),
            'name' => $source->name,
            'type' => $source->type,
            'value' => $source->value,
            'plan_id' => $source->plan_id,
            'limit_use' => $source->limit_use,
            'used_user_ids' => null,
            'started_at' => $source->started_at ?: time(),
            'ended_at' => $source->ended_at ?: strtotime('+10 years'),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function templates(Request $request)
    {
        $pageSize = (int) $request->input('per_page', $request->input('pageSize', 15));
        $query = Giftcard::query()->orderBy('id', 'desc');
        $templates = $query->paginate(
            perPage: max(1, min($pageSize, 1000)),
            page: (int) $request->input('page', $request->input('current', 1))
        );

        $templates->getCollection()->transform(fn(Giftcard $giftcard) => $this->formatGiftcard($giftcard));
        return $this->paginate($templates);
    }

    public function createTemplate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'plan_id' => 'nullable|integer',
            'period' => 'nullable|string|max:64',
            'price' => 'nullable|numeric|min:0',
        ]);

        $periodDays = match ((string) $request->input('period')) {
            'month_price' => 30,
            'quarter_price' => 90,
            'half_year_price' => 180,
            'year_price' => 365,
            'two_year_price' => 730,
            'three_year_price' => 1095,
            default => 0,
        };

        $giftcard = Giftcard::create([
            'code' => Helper::randomChar(16),
            'name' => $request->input('name'),
            'type' => $request->input('plan_id') ? 5 : 1,
            'value' => $request->input('plan_id') ? $periodDays : (int) round(((float) $request->input('price', 0)) * 100),
            'plan_id' => $request->input('plan_id'),
            'limit_use' => 1,
            'used_user_ids' => null,
            'started_at' => time(),
            'ended_at' => strtotime('+10 years'),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $this->success($this->formatGiftcard($giftcard));
    }

    public function generateCodes(Request $request)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:v2_giftcard,id',
            'count' => 'required|integer|min:1|max:10000',
        ]);

        $source = Giftcard::findOrFail($request->input('template_id'));
        $count = (int) $request->input('count', 1);

        DB::transaction(function () use ($source, $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->copyGiftcard($source);
            }
        });

        return $this->success([
            'count' => $count,
            'message' => '生成成功',
        ]);
    }

    public function codes(Request $request)
    {
        $pageSize = (int) $request->input('per_page', $request->input('page_size', $request->input('pageSize', 15)));
        $query = Giftcard::query()->orderBy('id', 'desc');

        if ($request->filled('template_id')) {
            $source = Giftcard::find($request->input('template_id'));
            if ($source) {
                $query->where('name', $source->name)
                    ->where('type', $source->type)
                    ->where('value', $source->value)
                    ->where('plan_id', $source->plan_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $codes = $query->paginate(
            perPage: max(1, min($pageSize, 500)),
            page: (int) $request->input('page', $request->input('current', 1))
        );

        $codes->getCollection()->transform(fn(Giftcard $giftcard) => $this->formatGiftcard($giftcard));
        return $this->paginate($codes);
    }

    public function toggleCode(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_giftcard,id',
        ]);

        return $this->success(true);
    }

    public function exportCodes(Request $request)
    {
        $query = Giftcard::query()->orderBy('id', 'asc');

        if ($request->filled('template_id')) {
            $source = Giftcard::find($request->input('template_id'));
            if ($source) {
                $query->where('name', $source->name)
                    ->where('type', $source->type)
                    ->where('value', $source->value)
                    ->where('plan_id', $source->plan_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $content = $query->pluck('code')->implode("\n");
        return response($content)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="gift_cards.txt"');
    }

    public function usages(Request $request)
    {
        return $this->success([]);
    }

    public function statistics(Request $request)
    {
        return $this->success([
            'total_stats' => [
                'templates_count' => Giftcard::count(),
                'active_templates_count' => Giftcard::count(),
                'codes_count' => Giftcard::count(),
                'used_codes_count' => Giftcard::query()->whereNotNull('used_user_ids')->count(),
                'usages_count' => Giftcard::query()->whereNotNull('used_user_ids')->count(),
            ],
            'daily_usages' => [],
            'type_stats' => [],
        ]);
    }

    public function types()
    {
        return $this->success([
            1 => '金额',
            2 => '时长',
            3 => '流量',
            4 => '重置',
            5 => '套餐',
        ]);
    }

    public function updateTemplate(Request $request)
    {
        return $this->fail([400, '当前礼品卡兼容模式不支持编辑模板']);
    }

    public function deleteTemplate(Request $request)
    {
        return $this->deleteCode($request);
    }

    public function updateCode(Request $request)
    {
        return $this->success(true);
    }

    public function deleteCode(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_giftcard,id',
        ]);

        Giftcard::where('id', $request->input('id'))->delete();
        return $this->success(true);
    }
}
