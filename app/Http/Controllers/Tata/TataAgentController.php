<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Models\TataAgent;
use App\Models\User;
use App\Services\Telephony\Tata\TataSyncService;
use App\Services\Telephony\TataException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tata Teleservices → Agents: CRM users on the left, Smartflo users on the
 * right, and the link between them. Pull mirrors Smartflo; Push creates a
 * Smartflo agent for a CRM user; Link/Unlink pair them by hand.
 */
class TataAgentController extends Controller
{
    public function __construct(private TataSyncService $sync) {}

    public function index()
    {
        $users = User::with(['tataAgent', 'center:id,name', 'roles:id,name'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $unlinked = TataAgent::whereNull('user_id')->orderBy('name')->get();

        $dialVia = app(\App\Services\Telephony\CallService::class)->dialVia();
        $needLoginCalling = TataAgent::whereNotNull('smartflo_user_id')->where('is_login_based_calling', false)->count();

        return view('tata.agents', [
            'dialVia'          => $dialVia,
            'needLoginCalling' => $needLoginCalling,
            'users'    => $users,
            'unlinked' => $unlinked,
            'stats'    => [
                'linked'   => $users->filter(fn ($u) => $u->tataAgent?->sync_status === 'linked')->count(),
                'missing'  => $users->filter(fn ($u) => ! $u->tataAgent)->count(),
                'unlinked' => $unlinked->count(),
            ],
        ]);
    }

    public function pull(): JsonResponse
    {
        try {
            $result = $this->sync->pullAgents();
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Pulled {$result['pulled']} Smartflo user(s): {$result['linked']} linked to CRM users, {$result['unlinked']} still unlinked.",
        ]);
    }

    public function push(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['nullable', 'string', 'min:8', 'max:64']]);

        try {
            $agent = $this->sync->pushAgent($user, $data['password'] ?? null);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $user->name . ' now exists in Smartflo' . ($agent->extension ? ' with extension ' . $agent->extension : '') . '.',
        ]);
    }

    public function update(User $user): JsonResponse
    {
        try {
            $this->sync->updateAgent($user);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Smartflo agent updated from the CRM profile.']);
    }

    /**
     * Create a new Smartflo user + agent from the full form.
     */
    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                => ['required', 'string', 'max:120'],
            'login_id'            => ['required', 'string', 'max:120'],
            'email'               => ['required', 'email', 'max:191'],
            'password'            => ['required', 'string', 'min:8', 'max:64'],
            'number'              => ['required', 'string', 'regex:/^\+?[0-9]{10,13}$/'],
            'login_based_calling' => ['nullable', 'boolean'],
            'assign_extension'    => ['nullable', 'boolean'],
            'block_web_login'     => ['nullable', 'boolean'],
            'route_call_through'  => ['nullable', Rule::in(['0', '1', '2', 0, 1, 2])],
            'link_user_id'        => ['nullable', 'exists:users,id'],
        ]);

        $attributes = [
            'create_agent'        => true,
            'status'              => true,
            'name'                => $data['name'],
            'login_id'            => $data['login_id'],
            'email'               => $data['email'],
            'password'            => $data['password'],
            'number'              => preg_replace('/\D/', '', $data['number']),
            'login_based_calling' => $request->boolean('login_based_calling', true),
            'assign_extension'    => $request->boolean('assign_extension', true),
            'block_web_login'     => $request->boolean('block_web_login', false),
            'route_call_through'  => (int) ($data['route_call_through'] ?? 2),
            'user_for_cdr'        => ['type' => 'agent', 'value' => []],
        ];

        try {
            $agent = $this->sync->createSmartfloUser($attributes, $data['link_user_id'] ?? null);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Smartflo user "' . $data['name'] . '" created'
                . ($agent->extension ? ' with extension ' . $agent->extension : '') . '.',
        ]);
    }

    /** Enable login-based (softphone) calling for all linked Smartflo agents. */
    public function enableSoftphone(): JsonResponse
    {
        try {
            $r = $this->sync->enableSoftphoneCalling();
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $r['updated'] . ' agent(s) now have login-based calling on'
                . ($r['failed'] ? ', ' . $r['failed'] . ' failed' : '')
                . '. Agents must be signed into the Smartflo softphone to receive calls there.',
        ]);
    }

    /** Prefill data for the "Modify Smartflo user" form. */
    public function details(TataAgent $agent): JsonResponse
    {
        return response()->json([
            'id'                  => $agent->id,
            'name'                => $agent->name,
            'email'               => $agent->email,
            'login_id'            => $agent->login_id,
            'number'              => $agent->follow_me_number,
            'extension'           => $agent->extension,
            'login_based_calling' => (bool) $agent->is_login_based_calling,
            'block_web_login'     => ! $agent->is_web_login,
            'block_agent'         => ! $agent->is_active,
            'crm_user'            => $agent->user?->name,
        ]);
    }

    /** Modify an existing Smartflo user. */
    public function modify(Request $request, TataAgent $agent): JsonResponse
    {
        $data = $request->validate([
            'name'                => ['nullable', 'string', 'max:120'],
            'email'               => ['nullable', 'email', 'max:191'],
            'number'              => ['nullable', 'string', 'regex:/^\+?[0-9]{10,13}$/'],
            'password'            => ['nullable', 'string', 'min:8', 'max:64'],
            'login_based_calling' => ['nullable', 'boolean'],
            'block_web_login'     => ['nullable', 'boolean'],
            'block_agent'         => ['nullable', 'boolean'],
        ]);

        $attributes = array_filter([
            'name'     => $data['name'] ?? null,
            'email'    => $data['email'] ?? null,
            'number'   => isset($data['number']) ? preg_replace('/\D/', '', $data['number']) : null,
            'password' => $data['password'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        foreach (['login_based_calling', 'block_web_login', 'block_agent'] as $flag) {
            if ($request->has($flag)) {
                $attributes[$flag] = $request->boolean($flag);
            }
        }

        try {
            $this->sync->modifySmartfloUser($agent, $attributes);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Smartflo user updated.']);
    }

    public function link(Request $request, TataAgent $agent): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);

        $this->sync->linkAgent($agent, User::findOrFail($data['user_id']));

        return response()->json(['success' => true, 'message' => 'Linked. The user will be rung on ' . ($agent->dialIdentity() ?: 'their profile phone') . '.']);
    }

    public function unlink(TataAgent $agent): JsonResponse
    {
        $this->sync->unlinkAgent($agent);

        return response()->json(['success' => true, 'message' => 'Unlinked.']);
    }
}
