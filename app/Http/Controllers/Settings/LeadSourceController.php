<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\LeadSource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class LeadSourceController extends Controller
{
    public const CHANNELS = [
        'meta'      => 'Meta (Facebook & Instagram)',
        'google'    => 'Google',
        'website'   => 'Website',
        'whatsapp'  => 'WhatsApp',
        'practo'    => 'Practo',
        'justdial'  => 'JustDial',
        'indiamart' => 'IndiaMart',
        'referral'  => 'Referral',
        'walkin'    => 'Walk-In',
        'camp'      => 'Camp',
        'event'     => 'Event',
        'csv'       => 'CSV Upload',
        'api'       => 'API',
        'other'     => 'Other',
    ];

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = LeadSource::withCount('leads', 'campaigns');

            return DataTables::of($query)
                ->addColumn('channel_badge', fn($s) =>
                    "<span class='badge bg-info text-dark'>" . (self::CHANNELS[$s->channel] ?? $s->channel) . "</span>"
                )
                ->addColumn('paid_badge', fn($s) =>
                    $s->is_paid
                        ? "<span class='badge bg-warning text-dark'>Paid</span>"
                        : "<span class='badge bg-secondary'>Organic</span>"
                )
                ->addColumn('status_badge', fn($s) =>
                    $s->is_active
                        ? "<span class='badge bg-success'>Active</span>"
                        : "<span class='badge bg-secondary'>Inactive</span>"
                )
                ->addColumn('action', fn($s) => view('settings.lead-sources._actions', ['source' => $s])->render())
                ->rawColumns(['channel_badge', 'paid_badge', 'status_badge', 'action'])
                ->make(true);
        }

        return view('settings.lead-sources.index', ['channels' => self::CHANNELS]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:191'],
            'channel'   => ['required', 'string', Rule::in(array_keys(self::CHANNELS))],
            'is_paid'   => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $base = Str::slug($data['name']);
        $slug = $base;
        $i = 1;
        while (LeadSource::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;

        LeadSource::create($data);

        return response()->json(['success' => true, 'message' => 'Lead source created.']);
    }

    public function edit(LeadSource $leadSource)
    {
        return response()->json($leadSource);
    }

    public function update(Request $request, LeadSource $leadSource)
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:191'],
            'channel'   => ['required', 'string', Rule::in(array_keys(self::CHANNELS))],
            'is_paid'   => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $leadSource->update($data);

        return response()->json(['success' => true, 'message' => 'Lead source updated.']);
    }

    public function destroy(LeadSource $leadSource)
    {
        if ($leadSource->leads()->count()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete: source has associated leads.'], 422);
        }

        $leadSource->delete();

        return response()->json(['success' => true, 'message' => 'Lead source deleted.']);
    }
}
