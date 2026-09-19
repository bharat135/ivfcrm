<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = Role::withCount('users')->with('permissions:id,name');

            return DataTables::of($query)
                ->addColumn('permissions_count', fn($r) => $r->permissions->count())
                ->addColumn('action', fn($r) => view('settings.roles._actions', ['role' => $r])->render())
                ->rawColumns(['action'])
                ->make(true);
        }

        $permissions = Permission::orderBy('name')->get()->groupBy(function ($p) {
            return explode('.', $p->name)[0];
        });

        return view('settings.roles.index', compact('permissions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $data['name']]);

        if (!empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return response()->json(['success' => true, 'message' => 'Role created.']);
    }

    public function edit(Role $role)
    {
        return response()->json([
            'role'        => $role,
            'permissions' => $role->permissions->pluck('name'),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return response()->json(['success' => true, 'message' => 'Role updated.']);
    }

    public function destroy(Role $role)
    {
        $protected = ['Super Admin', 'Management'];

        if (in_array($role->name, $protected)) {
            return response()->json(['success' => false, 'message' => "The \"{$role->name}\" role cannot be deleted."], 422);
        }

        $role->delete();

        return response()->json(['success' => true, 'message' => 'Role deleted.']);
    }

    public function permissions(Role $role)
    {
        return response()->json($role->permissions->pluck('name'));
    }
}
