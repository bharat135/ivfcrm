<?php

namespace App\Http\Controllers;

use App\Models\Center;
use App\Models\LeadSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live API documentation page and the API-token manager. An admin generates
 * a bearer token here and hands it to whoever is pushing leads into the CRM.
 */
class DeveloperController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->hasRole(['Super Admin', 'Admin', 'Management']),
            403,
            'Only an administrator can manage API access.',
        );
    }

    public function docs(Request $request)
    {
        $this->authorizeAdmin($request);

        return view('developers.index', [
            'tokens'  => $request->user()->tokens()->latest()->get(['id', 'name', 'last_used_at', 'created_at']),
            'sources' => LeadSource::orderBy('name')->get(['id', 'name']),
            'centers' => Center::orderBy('name')->get(['id', 'name', 'code']),
            'baseUrl' => url('/api/v1'),
        ]);
    }

    public function createToken(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        // A plain-text token is shown ONCE; only its hash is stored.
        $token = $request->user()->createToken($data['name']);

        return response()->json([
            'success' => true,
            'message' => 'API token created. Copy it now — it is shown only once.',
            'token'   => $token->plainTextToken,
            'id'      => $token->accessToken->id,
        ]);
    }

    public function revokeToken(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->user()->tokens()->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Token revoked.']);
    }
}
