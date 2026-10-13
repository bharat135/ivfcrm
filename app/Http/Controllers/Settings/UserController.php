<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        if ($request->ajax() || $request->wantsJson()) {
            $query = User::with(['roles:id,name', 'center:id,name'])
                ->select('users.*');

            return DataTables::of($query)
                ->addColumn('roles_list', fn($u) =>
                    $u->roles->map(fn($r) =>
                        "<span class='badge bg-primary me-1'>{$r->name}</span>"
                    )->implode('')
                )
                ->addColumn('status_badge', fn($u) =>
                    $u->is_active
                        ? "<span class='badge bg-success'>Active</span>"
                        : "<span class='badge bg-secondary'>Inactive</span>"
                )
                ->addColumn('action', fn($u) => view('settings.users._actions', ['user' => $u])->render())
                ->rawColumns(['roles_list', 'status_badge', 'action'])
                ->make(true);
        }

        $roles   = Role::orderBy('name')->get();
        $centers = Center::active()->get();

        return view('settings.users.index', compact('roles', 'centers'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:191'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'phone'         => ['nullable', 'string', 'max:20'],
            'employee_code' => ['nullable', 'string', 'max:40', 'unique:users,employee_code'],
            'password'      => ['required', 'string', 'min:8'],
            'center_id'     => ['nullable', 'exists:centers,id'],
            'designation'   => ['nullable', 'string', 'max:191'],
            'language'      => ['nullable', 'string', 'max:10'],
            'daily_lead_cap'=> ['nullable', 'integer', 'min:1'],
            'tata_agent_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9]{3,20}$/', 'unique:users,tata_agent_number'],
            'tata_c2c_support_key' => ['nullable', 'string', 'max:255'],
            'role'          => ['required', 'exists:roles,name'],
            'is_active'     => ['boolean'],
        ], [
            'tata_agent_number.regex'  => 'Enter the agent\'s Smartflo mobile, agent id (050…) or extension (060…), digits only.',
            'tata_agent_number.unique' => 'That Tata agent number is already mapped to another user.',
        ]);

        $user = User::create([
            'name'          => $data['name'],
            'email'         => $data['email'],
            'phone'         => $data['phone'] ?? null,
            'employee_code' => $data['employee_code'] ?? null,
            'password'      => Hash::make($data['password']),
            'center_id'     => $data['center_id'] ?? null,
            'designation'   => $data['designation'] ?? null,
            'language'      => $data['language'] ?? 'en',
            'daily_lead_cap'=> $data['daily_lead_cap'] ?? null,
            'tata_agent_number' => $data['tata_agent_number'] ?? null,
            'tata_c2c_support_key' => $data['tata_c2c_support_key'] ?? null,
            'is_active'     => $request->boolean('is_active', true),
        ]);

        $user->assignRole($data['role']);
        Doctor::syncFromUser($user);

        return response()->json(['success' => true, 'message' => 'User created successfully.']);
    }

    public function edit(User $user)
    {
        $this->authorize('update', $user);

        return response()->json([
            'user'  => $user->load('roles:id,name'),
            'role'  => $user->getRoleNames()->first(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:191'],
            'email'         => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'         => ['nullable', 'string', 'max:20'],
            'employee_code' => ['nullable', 'string', 'max:40', Rule::unique('users', 'employee_code')->ignore($user->id)],
            'password'      => ['nullable', 'string', 'min:8'],
            'center_id'     => ['nullable', 'exists:centers,id'],
            'designation'   => ['nullable', 'string', 'max:191'],
            'language'      => ['nullable', 'string', 'max:10'],
            'daily_lead_cap'=> ['nullable', 'integer', 'min:1'],
            'tata_agent_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9]{3,20}$/',
                                    Rule::unique('users', 'tata_agent_number')->ignore($user->id)],
            'tata_c2c_support_key' => ['nullable', 'string', 'max:255'],
            'role'          => ['required', 'exists:roles,name'],
            'is_active'     => ['boolean'],
        ], [
            'tata_agent_number.regex'  => 'Enter the agent\'s Smartflo mobile, agent id (050…) or extension (060…), digits only.',
            'tata_agent_number.unique' => 'That Tata agent number is already mapped to another user.',
        ]);

        $updateData = [
            'name'          => $data['name'],
            'email'         => $data['email'],
            'phone'         => $data['phone'] ?? null,
            'employee_code' => $data['employee_code'] ?? null,
            'center_id'     => $data['center_id'] ?? null,
            'designation'   => $data['designation'] ?? null,
            'language'      => $data['language'] ?? 'en',
            'daily_lead_cap'=> $data['daily_lead_cap'] ?? null,
            'tata_agent_number' => $data['tata_agent_number'] ?? null,
            'tata_c2c_support_key' => $data['tata_c2c_support_key'] ?? null,
            'is_active'     => $request->boolean('is_active', true),
        ];

        if (!empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $user->update($updateData);
        $user->syncRoles([$data['role']]);
        Doctor::syncFromUser($user);

        return response()->json(['success' => true, 'message' => 'User updated successfully.']);
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        if ($user->id === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(['success' => true, 'message' => 'User deleted.']);
    }

    public function toggleStatus(User $user)
    {
        $this->authorize('update', $user);

        $user->update(['is_active' => !$user->is_active]);
        Doctor::syncFromUser($user);

        return response()->json([
            'success' => true,
            'is_active' => $user->is_active,
            'message' => 'User ' . ($user->is_active ? 'activated' : 'deactivated') . '.',
        ]);
    }
}
