<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\TataDepartment;
use App\Models\TataIvr;
use App\Models\TataNumber;
use App\Services\Telephony\Tata\TataSyncService;
use App\Services\Telephony\TataException;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tata Teleservices → Numbers & routing: DIDs (with per-centre caller id),
 * departments and IVRs pulled from Smartflo.
 */
class TataNumberController extends Controller
{
    public function __construct(private TataSyncService $sync, private SettingsRepository $settings) {}

    public function index()
    {
        return view('tata.numbers', [
            'numbers'     => TataNumber::with('center:id,name')->orderBy('did')->get(),
            'departments' => TataDepartment::orderBy('name')->get(),
            'ivrs'        => TataIvr::orderBy('name')->get(),
            'centers'     => Center::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function pull(): JsonResponse
    {
        try {
            $numbers = $this->sync->pullNumbers();
            $departments = $this->sync->pullDepartments();
            $ivrs = $this->sync->pullIvrs();
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Pulled {$numbers} number(s), {$departments} department(s) and {$ivrs} IVR(s) from Smartflo.",
        ]);
    }

    public function setDefault(TataNumber $number): JsonResponse
    {
        TataNumber::where('is_default', true)->update(['is_default' => false]);
        $number->update(['is_default' => true]);

        $this->settings->set('telephony', 'tata_caller_id', $number->did);

        return response()->json(['success' => true, 'message' => $number->did . ' is now the default caller ID.']);
    }

    public function update(Request $request, TataNumber $number): JsonResponse
    {
        $data = $request->validate([
            'center_id' => ['nullable', 'exists:centers,id'],
            'name'      => ['nullable', 'string', 'max:191'],
        ]);

        $number->update([
            'center_id' => $data['center_id'] ?? null,
            'name'      => $data['name'] ?? $number->name,
        ]);

        return response()->json(['success' => true, 'message' => 'Number updated.']);
    }
}
