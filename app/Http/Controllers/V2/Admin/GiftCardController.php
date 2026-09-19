<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Giftcard;
use App\Models\GiftcardUsage;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GiftCardController extends Controller
{
    private function formatGiftcard(Giftcard $giftcard): array
    {
        $usedUserIds = (array)($giftcard->used_user_ids ?: []);
        $plan = $giftcard->relationLoaded('plan') ? $giftcard->plan : null;
        $type = (int)$giftcard->type;
        $rootUsed = $this->isUsed($giftcard) ? 1 : 0;
        $codesCount = isset($giftcard->codes_count) ? 1 + (int)$giftcard->codes_count : 1;
        $usedCount = isset($giftcard->used_codes_count)
            ? $rootUsed + (int)$giftcard->used_codes_count
            : $rootUsed;

        return [
            'id' => (int)$giftcard->id,
            'template_id' => (int)($giftcard->template_id ?: $giftcard->id),
            'name' => (string)$giftcard->name,
            'plan_id' => $giftcard->plan_id ? (int)$giftcard->plan_id : null,
            'plan_name' => $plan?->name ?? '',
            'period' => $this->formatPeriod($giftcard),
            'price' => (int)($type === 1 ? $giftcard->value : 0),
            'code' => (string)$giftcard->code,
            'status' => $this->isAvailable($giftcard) ? 0 : 1,
            'enabled' => (bool)$giftcard->enabled,
            'used_at' => $giftcard->used_at ? (int)$giftcard->used_at : null,
            'used_by_user_id' => $giftcard->used_by_user_id
                ? (int)$giftcard->used_by_user_id
                : ($usedUserIds[0] ?? null),
            'codes_count' => $codesCount,
            'used_count' => $usedCount,
            'created_at' => (int)$giftcard->created_at,
            'updated_at' => (int)$giftcard->updated_at,
        ];
    }

    private function isUsed(Giftcard $giftcard): bool
    {
        return (int)($giftcard->used_at ?? 0) > 0
            || ($giftcard->limit_use !== null && (int)$giftcard->limit_use <= 0);
    }

    private function isAvailable(Giftcard $giftcard): bool
    {
        $now = time();
        return (bool)$giftcard->enabled
            && !$this->isUsed($giftcard)
            && (!(int)$giftcard->started_at || (int)$giftcard->started_at <= $now)
            && (!(int)$giftcard->ended_at || (int)$giftcard->ended_at >= $now);
    }

    private function formatPeriod(Giftcard $giftcard): string
    {
        $type = (int)$giftcard->type;
        if ($type === 2 || $type === 5) {
            return ((int)$giftcard->value) . '天';
        }
        if ($type === 3) {
            return ((int)$giftcard->value) . 'GB';
        }
        if ($type === 4) {
            return '重置';
        }
        return '金额';
    }

    private function periodDays(?string $period): int
    {
        return match ((string)$period) {
            'month_price' => 30,
            'quarter_price' => 90,
            'half_year_price' => 180,
            'year_price' => 365,
            'two_year_price' => 730,
            'three_year_price' => 1095,
            default => 0,
        };
    }

    private function copyGiftcard(Giftcard $source): Giftcard
    {
        return Giftcard::create([
            'template_id' => $source->template_id ?: $source->id,
            'code' => Helper::randomChar(16),
            'name' => $source->name,
            'type' => $source->type,
            'value' => $source->value,
            'plan_id' => $source->plan_id,
            'limit_use' => $source->limit_use === null ? null : 1,
            'enabled' => true,
            'used_user_ids' => null,
            'used_at' => null,
            'used_by_user_id' => null,
            'started_at' => $source->started_at ?: time(),
            'ended_at' => $source->ended_at ?: strtotime('+10 years'),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function templates(Request $request)
    {
        $pageSize = (int)$request->input('per_page', $request->input('pageSize', 15));
        $templates = Giftcard::query()
            ->whereNull('template_id')
            ->with('plan:id,name')
            ->withCount('codes')
            ->withCount([
                'codes as used_codes_count' => static function ($query) {
                    $query->where(static function ($used) {
                        $used->whereNotNull('used_at')->orWhere('limit_use', '<=', 0);
                    });
                },
            ])
            ->orderBy('id', 'desc')
            ->paginate(
                perPage: max(1, min($pageSize, 1000)),
                page: (int)$request->input('page', $request->input('current', 1))
            );

        $templates->getCollection()->transform(fn (Giftcard $giftcard) => $this->formatGiftcard($giftcard));
        return $this->paginate($templates);
    }

    public function createTemplate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'plan_id' => 'nullable|integer|exists:v2_plan,id',
            'period' => 'nullable|string|max:64',
            'price' => 'nullable|integer|min:0',
        ]);

        $planId = $request->input('plan_id');
        $giftcard = Giftcard::create([
            'template_id' => null,
            'code' => Helper::randomChar(16),
            'name' => $request->input('name'),
            'type' => $planId ? 5 : 1,
            'value' => $planId
                ? $this->periodDays($request->input('period'))
                : (int)$request->input('price', 0),
            'plan_id' => $planId,
            'limit_use' => 1,
            'enabled' => true,
            'used_user_ids' => null,
            'used_at' => null,
            'used_by_user_id' => null,
            'started_at' => time(),
            'ended_at' => strtotime('+10 years'),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $giftcard->load('plan:id,name');
        return $this->success($this->formatGiftcard($giftcard));
    }

    public function generateCodes(Request $request)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:v2_giftcard,id',
            'count' => 'required|integer|min:1|max:10000',
        ]);

        $source = Giftcard::whereNull('template_id')->findOrFail($request->input('template_id'));
        $count = (int)$request->input('count', 1);
        DB::transaction(function () use ($source, $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->copyGiftcard($source);
            }
        }, 3);

        return $this->success(['count' => $count, 'message' => '生成成功']);
    }

    public function codes(Request $request)
    {
        $pageSize = (int)$request->input('per_page', $request->input('page_size', $request->input('pageSize', 15)));
        $query = Giftcard::query()->with('plan:id,name')->orderBy('id', 'desc');

        if ($request->filled('template_id')) {
            $templateId = (int)$request->input('template_id');
            $query->where(static function ($codes) use ($templateId) {
                $codes->where('id', $templateId)->orWhere('template_id', $templateId);
            });
        }

        $codes = $query->paginate(
            perPage: max(1, min($pageSize, 500)),
            page: (int)$request->input('page', $request->input('current', 1))
        );
        $codes->getCollection()->transform(fn (Giftcard $giftcard) => $this->formatGiftcard($giftcard));
        return $this->paginate($codes);
    }

    public function toggleCode(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_giftcard,id']);
        $giftcard = Giftcard::findOrFail($request->input('id'));
        $giftcard->enabled = !$giftcard->enabled;
        $giftcard->saveOrFail();
        return $this->success(['enabled' => (bool)$giftcard->enabled]);
    }

    public function exportCodes(Request $request)
    {
        $query = Giftcard::query()->orderBy('id', 'asc');
        if ($request->filled('template_id')) {
            $templateId = (int)$request->input('template_id');
            $query->where(static function ($codes) use ($templateId) {
                $codes->where('id', $templateId)->orWhere('template_id', $templateId);
            });
        }

        $content = $query->pluck('code')->implode("\n");
        return response($content)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="gift_cards.txt"');
    }

    public function usages(Request $request)
    {
        $pageSize = max(1, min((int)$request->input('page_size', 20), 200));
        $query = GiftcardUsage::query()
            ->with(['giftcard:id,name,code', 'user:id,email'])
            ->orderByDesc('id');
        if ($request->filled('giftcard_id')) {
            $query->where('giftcard_id', (int)$request->input('giftcard_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int)$request->input('user_id'));
        }

        $usages = $query->paginate($pageSize);
        $usages->getCollection()->transform(static function (GiftcardUsage $usage) {
            return [
                'id' => (int)$usage->id,
                'giftcard_id' => (int)$usage->giftcard_id,
                'code' => (string)($usage->giftcard->code ?? ''),
                'name' => (string)($usage->giftcard->name ?? ''),
                'user_id' => (int)$usage->user_id,
                'user_email' => (string)($usage->user->email ?? ''),
                'type' => (int)$usage->type,
                'value' => $usage->value === null ? null : (int)$usage->value,
                'plan_id' => $usage->plan_id ? (int)$usage->plan_id : null,
                'ip' => (string)($usage->ip ?? ''),
                'created_at' => (int)$usage->created_at,
            ];
        });
        return $this->paginate($usages);
    }

    public function statistics(Request $request)
    {
        $dailyUsages = GiftcardUsage::query()
            ->where('created_at', '>=', strtotime('-30 days'))
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        $typeStats = GiftcardUsage::query()
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->get();

        return $this->success([
            'total_stats' => [
                'templates_count' => Giftcard::whereNull('template_id')->count(),
                'active_templates_count' => Giftcard::whereNull('template_id')->where('enabled', 1)->count(),
                'codes_count' => Giftcard::count(),
                'used_codes_count' => Giftcard::whereNotNull('used_at')->count(),
                'usages_count' => GiftcardUsage::count(),
            ],
            'daily_usages' => $dailyUsages,
            'type_stats' => $typeStats,
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
        $request->validate([
            'id' => 'required|integer|exists:v2_giftcard,id',
            'name' => 'sometimes|required|string|max:255',
            'plan_id' => 'sometimes|nullable|integer|exists:v2_plan,id',
            'period' => 'sometimes|nullable|string|max:64',
            'price' => 'sometimes|nullable|integer|min:0',
            'enabled' => 'sometimes|boolean',
        ]);

        $giftcard = Giftcard::whereNull('template_id')->findOrFail($request->input('id'));
        if ($request->has('name')) {
            $giftcard->name = $request->input('name');
        }
        if ($request->has('enabled')) {
            $giftcard->enabled = (bool)$request->input('enabled');
        }
        if ($request->has('plan_id')) {
            $giftcard->plan_id = $request->input('plan_id');
            $giftcard->type = $giftcard->plan_id ? 5 : 1;
        }
        if ((int)$giftcard->type === 5 && $request->has('period')) {
            $giftcard->value = $this->periodDays($request->input('period'));
        }
        if ((int)$giftcard->type === 1 && $request->has('price')) {
            $giftcard->value = (int)$request->input('price');
        }
        $giftcard->saveOrFail();
        $giftcard->load('plan:id,name');
        return $this->success($this->formatGiftcard($giftcard));
    }

    public function deleteTemplate(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_giftcard,id']);
        $templateId = (int)$request->input('id');
        DB::transaction(function () use ($templateId) {
            $ids = Giftcard::where('id', $templateId)
                ->orWhere('template_id', $templateId)
                ->pluck('id');
            GiftcardUsage::whereIn('giftcard_id', $ids)->delete();
            Giftcard::whereIn('id', $ids)->delete();
        }, 3);
        return $this->success(true);
    }

    public function updateCode(Request $request)
    {
        $id = (int)$request->input('id');
        $request->validate([
            'id' => 'required|integer|exists:v2_giftcard,id',
            'code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('v2_giftcard', 'code')->ignore($id)],
            'enabled' => 'sometimes|boolean',
            'ended_at' => 'sometimes|nullable|integer',
        ]);

        $giftcard = Giftcard::findOrFail($id);
        $giftcard->fill($request->only(['code', 'enabled', 'ended_at']));
        $giftcard->saveOrFail();
        $giftcard->load('plan:id,name');
        return $this->success($this->formatGiftcard($giftcard));
    }

    public function deleteCode(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_giftcard,id']);
        $id = (int)$request->input('id');
        DB::transaction(function () use ($id) {
            GiftcardUsage::where('giftcard_id', $id)->delete();
            Giftcard::whereKey($id)->delete();
        }, 3);
        return $this->success(true);
    }
}
