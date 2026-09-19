<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Models\Disposition;
use App\Models\TataDisposition;
use App\Services\Telephony\Tata\TataSyncService;
use App\Services\Telephony\TataException;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;

/**
 * Tata Teleservices → Dispositions: the CRM catalogue against the Smartflo
 * dialer disposition list, synced both ways.
 */
class TataDispositionController extends Controller
{
    public function __construct(private TataSyncService $sync, private SettingsRepository $settings) {}

    public function index()
    {
        $links = TataDisposition::all()->keyBy('disposition_id');

        $dispositions = Disposition::with('parent:id,name')
            ->orderByRaw('COALESCE(parent_id, id), parent_id IS NOT NULL, sort_order')
            ->get()
            ->map(fn (Disposition $d) => [
                'model' => $d,
                'link'  => $links->get($d->id),
            ]);

        $orphans = TataDisposition::whereNull('disposition_id')->get();

        return view('tata.dispositions', [
            'rows'     => $dispositions,
            'orphans'  => $orphans,
            'listId'   => $this->settings->get('telephony', 'tata_disposition_list_id'),
            'listName' => TataSyncService::DISPOSITION_LIST_NAME,
            'stats'    => [
                'synced'  => $links->filter(fn ($l) => $l->sync_status === 'synced')->count(),
                'pending' => $dispositions->filter(fn ($r) => ! $r['link'] || $r['link']->sync_status !== 'synced')->count(),
                'errors'  => $links->filter(fn ($l) => $l->sync_status === 'error')->count(),
            ],
        ]);
    }

    public function sync(): JsonResponse
    {
        return $this->run(fn () => $this->sync->syncDispositions(), fn ($r) =>
            "Synced with Smartflo list #{$r['list_id']}: {$r['pulled']} pulled, {$r['created_in_crm']} created in the CRM, {$r['pushed']} pushed to Smartflo.");
    }

    public function push(): JsonResponse
    {
        return $this->run(fn () => $this->sync->pushDispositions(), fn ($r) => "Pushed {$r['pushed']} disposition(s) to Smartflo.");
    }

    public function pull(): JsonResponse
    {
        return $this->run(fn () => $this->sync->pullDispositions(), fn ($r) => "Pulled {$r['pulled']} Smartflo disposition(s); {$r['created_in_crm']} were new to the CRM.");
    }

    private function run(callable $action, callable $message): JsonResponse
    {
        try {
            $result = $action();
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => $message($result), 'result' => $result]);
    }
}
