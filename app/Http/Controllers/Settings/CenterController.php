<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\City;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class CenterController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = Center::withTrashed()
                ->with(['city:id,name', 'state:id,name'])
                ->withCount('users', 'leads');

            return DataTables::of($query)
                ->addColumn('status_badge', fn($c) =>
                    $c->deleted_at
                        ? "<span class='badge bg-danger'>Deleted</span>"
                        : ($c->is_active ? "<span class='badge bg-success'>Active</span>" : "<span class='badge bg-secondary'>Inactive</span>")
                )
                ->addColumn('action', fn($c) => view('settings.centers._actions', ['center' => $c])->render())
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        $states = State::orderBy('name')->get();

        return view('settings.centers.index', compact('states'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:191'],
            'code'     => ['required', 'string', 'max:30', 'unique:centers,code'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'email'    => ['nullable', 'email'],
            'address'  => ['nullable', 'string'],
            'city_id'  => ['nullable', 'exists:cities,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'pincode'  => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active'=> ['boolean'],
        ]);

        Center::create($data);

        return response()->json(['success' => true, 'message' => 'Center created.']);
    }

    public function edit(Center $center)
    {
        return response()->json($center->load(['city:id,name', 'state:id,name']));
    }

    public function update(Request $request, Center $center)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:191'],
            'code'     => ['required', 'string', 'max:30', Rule::unique('centers', 'code')->ignore($center->id)],
            'phone'    => ['nullable', 'string', 'max:20'],
            'email'    => ['nullable', 'email'],
            'address'  => ['nullable', 'string'],
            'city_id'  => ['nullable', 'exists:cities,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'pincode'  => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active'=> ['boolean'],
        ]);

        $center->update($data);

        return response()->json(['success' => true, 'message' => 'Center updated.']);
    }

    public function destroy(Center $center)
    {
        if ($center->leads()->count()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete: center has associated leads.'], 422);
        }

        $center->delete();

        return response()->json(['success' => true, 'message' => 'Center deleted.']);
    }
}
