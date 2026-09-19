<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MachineGroupController extends Controller
{
    public function fetch()
    {
        $groups = MachineGroup::query()
            ->withCount('machines')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (MachineGroup $group) {
                return [
                    'id' => (int) $group->id,
                    'name' => (string) $group->name,
                    'sort' => (int) $group->sort,
                    'machine_count' => (int) $group->machines_count,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ];
            })
            ->values();

        return response(['data' => $groups]);
    }

    public function save(Request $request)
    {
        $groupId = (int) $request->input('id', 0);
        $request->merge(['name' => trim((string) $request->input('name', ''))]);
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_machine_group,id',
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('v2_machine_group', 'name')->ignore($groupId ?: null),
            ],
        ]);
        $name = trim((string) $params['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => '分组名称不能为空']);
        }

        if ($groupId > 0) {
            $group = MachineGroup::findOrFail($groupId);
            $group->update(['name' => $name]);
        } else {
            $group = MachineGroup::create([
                'name' => $name,
                'sort' => ((int) MachineGroup::max('sort')) + 1,
            ]);
        }

        return response([
            'data' => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'sort' => (int) $group->sort,
            ],
        ]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine_group,id',
        ]);
        $group = MachineGroup::findOrFail((int) $params['id']);

        DB::transaction(function () use ($group) {
            Machine::query()
                ->where('machine_group_id', $group->id)
                ->update(['machine_group_id' => null]);
            $group->delete();
        });

        return response(['data' => true]);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|distinct|exists:v2_machine_group,id',
        ]);

        DB::transaction(function () use ($params) {
            foreach (array_values($params['ids']) as $index => $groupId) {
                MachineGroup::query()
                    ->whereKey((int) $groupId)
                    ->update(['sort' => $index + 1]);
            }
        });

        return response(['data' => true]);
    }
}
