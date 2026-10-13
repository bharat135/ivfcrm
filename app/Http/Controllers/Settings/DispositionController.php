<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Disposition;
use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

class DispositionController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = Disposition::with(['parent:id,name', 'movesToStage:id,name'])
                ->withCount('children');

            return DataTables::of($query)
                // The data key is snake_case but the relation is movesToStage;
                // Yajra cannot bridge that on its own, so search/sort explicitly.
                ->filterColumn('moves_to_stage.name', function ($q, $keyword) {
                    $q->whereHas('movesToStage', fn ($s) => $s->where('name', 'like', "%{$keyword}%"));
                })
                ->orderColumn('moves_to_stage.name', function ($q, $direction) {
                    $q->orderBy(\App\Models\PipelineStage::select('name')->whereColumn('pipeline_stages.id', 'dispositions.moves_to_stage_id')->limit(1), $direction);
                })
                ->addColumn('type', fn($d) =>
                    $d->parent_id ? "<span class='badge bg-secondary'>Sub</span>" : "<span class='badge bg-primary'>Primary</span>"
                )
                ->addColumn('flags', function ($d) {
                    $flags = [];
                    if ($d->requires_remark)   $flags[] = "<span class='badge bg-info text-dark me-1'>Remark</span>";
                    if ($d->requires_followup) $flags[] = "<span class='badge bg-warning text-dark me-1'>Follow-up</span>";
                    if ($d->is_won)            $flags[] = "<span class='badge bg-success me-1'>Won</span>";
                    if ($d->is_lost)           $flags[] = "<span class='badge bg-danger me-1'>Lost</span>";
                    return implode('', $flags);
                })
                ->addColumn('status_badge', fn($d) =>
                    $d->is_active
                        ? "<span class='badge bg-success'>Active</span>"
                        : "<span class='badge bg-secondary'>Inactive</span>"
                )
                ->addColumn('action', fn($d) => view('settings.dispositions._actions', ['disposition' => $d])->render())
                ->rawColumns(['type', 'flags', 'status_badge', 'action'])
                ->make(true);
        }

        $parents = Disposition::active()->primary()->orderBy('sort_order')->get();
        $stages  = PipelineStage::active()->get();

        return view('settings.dispositions.index', compact('parents', 'stages'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:191'],
            'parent_id'         => ['nullable', 'exists:dispositions,id'],
            'color'             => ['nullable', 'string', 'max:20'],
            'moves_to_stage_id' => ['nullable', 'exists:pipeline_stages,id'],
            'requires_remark'   => ['boolean'],
            'requires_followup' => ['boolean'],
            'is_won'            => ['boolean'],
            'is_lost'           => ['boolean'],
            'sort_order'        => ['nullable', 'integer'],
            'is_active'         => ['boolean'],
        ]);

        $data['slug'] = Str::slug($data['name']) . '-' . Str::random(4);

        Disposition::create($data);

        return response()->json(['success' => true, 'message' => 'Disposition saved.']);
    }

    public function edit(Disposition $disposition)
    {
        return response()->json($disposition);
    }

    public function update(Request $request, Disposition $disposition)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:191'],
            'parent_id'         => ['nullable', 'exists:dispositions,id'],
            'color'             => ['nullable', 'string', 'max:20'],
            'moves_to_stage_id' => ['nullable', 'exists:pipeline_stages,id'],
            'requires_remark'   => ['boolean'],
            'requires_followup' => ['boolean'],
            'is_won'            => ['boolean'],
            'is_lost'           => ['boolean'],
            'sort_order'        => ['nullable', 'integer'],
            'is_active'         => ['boolean'],
        ]);

        $disposition->update($data);

        return response()->json(['success' => true, 'message' => 'Disposition updated.']);
    }

    public function destroy(Disposition $disposition)
    {
        if ($disposition->children()->count()) {
            return response()->json(['success' => false, 'message' => 'Delete sub-dispositions first.'], 422);
        }

        $disposition->delete();

        return response()->json(['success' => true, 'message' => 'Disposition deleted.']);
    }

    public function reorder(Request $request)
    {
        $request->validate(['items' => ['required', 'array']]);

        foreach ($request->items as $item) {
            Disposition::where('id', $item['id'])->update(['sort_order' => $item['order']]);
        }

        return response()->json(['success' => true]);
    }
}
