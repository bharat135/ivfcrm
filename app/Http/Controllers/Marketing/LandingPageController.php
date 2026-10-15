<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Center;
use App\Models\LandingPage;
use App\Models\LeadSource;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Landing page builder.
 *
 * Pages are rendered server-side from structured fields (headline, USPs, form
 * fields, theme) rather than free HTML, so every page automatically posts back
 * into the CRM with the right attribution and routing.
 */
class LandingPageController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = LandingPage::with(['campaign:id,name', 'center:id,name', 'creator:id,name'])
                ->withCount('submissionRecords')
                ->when($request->filled('published'), fn ($q) => $q->where('is_published', $request->boolean('published')))
                ->when($request->filled('campaign_id'), fn ($q) => $q->where('campaign_id', $request->integer('campaign_id')))
                ->when($request->filled('center_id'), fn ($q) => $q->where('center_id', $request->integer('center_id')));

            return DataTables::of($query)
                ->addColumn('status_badge', fn (LandingPage $p) => $p->is_published
                    ? '<span class="badge bg-success">Published</span>'
                    : '<span class="badge bg-secondary">Draft</span>')
                ->addColumn('url', fn (LandingPage $p) =>
                    '<a href="' . e($p->publicUrl()) . '" target="_blank" class="small text-truncate d-inline-block" style="max-width:220px">/lp/' . e($p->slug) . '</a>')
                ->addColumn('conversion', fn (LandingPage $p) => $p->conversionRate() . '%')
                ->addColumn('action', fn (LandingPage $p) => view('marketing.landing._actions', ['page' => $p])->render())
                ->rawColumns(['status_badge', 'url', 'action'])
                ->make(true);
        }

        return view('marketing.landing.index', [
            'campaigns' => \App\Models\Campaign::orderByDesc('id')->get(['id', 'name']),
            'centers'   => \App\Models\Center::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return view('marketing.landing.form', [
            'page'      => new LandingPage(['form_fields' => LandingPage::DEFAULT_FORM_FIELDS]),
            'sources'   => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'campaigns' => Campaign::orderByDesc('id')->get(['id', 'name']),
            'centers'   => Center::where('is_active', true)->orderBy('name')->get(),
            'agents'    => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'templates' => WhatsappTemplate::approved()->orderBy('name')->get(),
        ]);
    }

    public function edit(LandingPage $landingPage)
    {
        return view('marketing.landing.form', [
            'page'      => $landingPage,
            'sources'   => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'campaigns' => Campaign::orderByDesc('id')->get(['id', 'name']),
            'centers'   => Center::where('is_active', true)->orderBy('name')->get(),
            'agents'    => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'templates' => WhatsappTemplate::approved()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $page = LandingPage::create($data + ['created_by' => Auth::id()]);

        $this->storeImages($request, $page);

        return redirect()
            ->route('marketing.landing.edit', $page)
            ->with('success', 'Landing page created. Preview it, then publish when ready.');
    }

    public function update(Request $request, LandingPage $landingPage)
    {
        $data = $this->validated($request, $landingPage);

        $landingPage->update($data);

        $this->storeImages($request, $landingPage);

        return redirect()
            ->route('marketing.landing.edit', $landingPage)
            ->with('success', 'Landing page updated.');
    }

    public function togglePublish(LandingPage $landingPage): JsonResponse
    {
        $publishing = ! $landingPage->is_published;

        $landingPage->update([
            'is_published' => $publishing,
            'published_at' => $publishing ? ($landingPage->published_at ?: now()) : $landingPage->published_at,
        ]);

        return response()->json([
            'success' => true,
            'message' => $publishing ? 'Page is live at ' . $landingPage->publicUrl() : 'Page unpublished.',
            'url'     => $landingPage->publicUrl(),
        ]);
    }

    /**
     * Duplicate an existing page — the fastest way to spin up a variant test.
     */
    public function duplicate(LandingPage $landingPage): JsonResponse
    {
        $copy = $landingPage->replicate([
            'uuid', 'slug', 'views', 'submissions', 'published_at', 'is_published',
        ]);

        $copy->name         = $landingPage->name . ' (copy)';
        $copy->slug         = Str::slug($landingPage->name) . '-' . Str::lower(Str::random(5));
        $copy->uuid         = (string) Str::uuid();
        $copy->is_published = false;
        $copy->views        = 0;
        $copy->submissions  = 0;
        $copy->created_by   = Auth::id();
        $copy->save();

        return response()->json([
            'success'  => true,
            'message'  => 'Page duplicated.',
            'redirect' => route('marketing.landing.edit', $copy),
        ]);
    }

    public function destroy(LandingPage $landingPage): JsonResponse
    {
        $landingPage->delete();

        return response()->json(['success' => true, 'message' => 'Landing page deleted.']);
    }

    /**
     * Submissions table for one page.
     */
    public function submissions(Request $request, LandingPage $landingPage)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return DataTables::of($landingPage->submissionRecords()->with('lead:id,name,mobile,status'))
                ->addColumn('contact', fn ($s) => e($s->payload['name'] ?? '—') . '<br><small class="text-muted">' . e($s->payload['mobile'] ?? '') . '</small>')
                ->addColumn('utm', fn ($s) => e(collect($s->utm ?? [])->filter()->join(' · ')) ?: '—')
                ->addColumn('lead_link', fn ($s) => $s->lead
                    ? '<a href="' . route('leads.show', $s->lead) . '">' . e($s->lead->name ?: 'Lead #' . $s->lead->id) . '</a>'
                    : '<span class="text-muted">Not created</span>')
                ->addColumn('submitted', fn ($s) => $s->created_at->format('d M Y, g:i A'))
                ->rawColumns(['contact', 'lead_link'])
                ->make(true);
        }

        return view('marketing.landing.submissions', ['page' => $landingPage]);
    }

    /**
     * Live preview of an unpublished page for the editor.
     */
    public function preview(LandingPage $landingPage)
    {
        return view('landing.render', [
            'page'      => $landingPage,
            'isPreview' => true,
        ]);
    }

    // -----------------------------------------------------------------

    private function validated(Request $request, ?LandingPage $page = null): array
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:191'],
            'slug'          => ['nullable', 'string', 'max:120', 'alpha_dash',
                                Rule::unique('landing_pages', 'slug')->ignore($page?->id)],
            'title'         => ['nullable', 'string', 'max:191'],
            'headline'      => ['required', 'string', 'max:191'],
            'subheadline'   => ['nullable', 'string', 'max:500'],
            'template'      => ['required', Rule::in(['clinic', 'minimal', 'longform'])],
            'theme_color'   => ['nullable', 'string', 'max:20'],
            'cta_text'      => ['nullable', 'string', 'max:60'],
            'thank_you_message' => ['nullable', 'string', 'max:500'],
            'redirect_url'  => ['nullable', 'url'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'body_html'     => ['nullable', 'string'],
            'custom_css'    => ['nullable', 'string', 'max:20000'],
            'sections'      => ['nullable', 'array'],
            'form_fields'   => ['nullable', 'array'],
            'form_fields.*.key'      => ['required', 'string', 'max:60'],
            'form_fields.*.label'    => ['required', 'string', 'max:120'],
            'form_fields.*.type'     => ['required', 'string', 'max:20'],
            'lead_source_id'=> ['nullable', 'exists:lead_sources,id'],
            'campaign_id'   => ['nullable', 'exists:campaigns,id'],
            'center_id'     => ['nullable', 'exists:centers,id'],
            'assign_to'     => ['nullable', 'exists:users,id'],
            'whatsapp_template_id' => ['nullable', 'exists:whatsapp_templates,id'],
            'meta_pixel_id' => ['nullable', 'string', 'max:60'],
            'google_analytics_id' => ['nullable', 'string', 'max:60'],
            'seo'           => ['nullable', 'array'],
            'hero_image'    => ['nullable', 'image', 'max:4096'],
            'logo_image'    => ['nullable', 'image', 'max:2048'],
        ]);

        // Normalise the repeater: drop rows the user emptied out.
        if (isset($data['form_fields'])) {
            $data['form_fields'] = array_values(array_filter(
                array_map(fn ($field) => [
                    'key'      => Str::slug($field['key'], '_'),
                    'label'    => $field['label'],
                    'type'     => $field['type'],
                    'required' => ! empty($field['required']),
                ], $data['form_fields']),
                fn ($field) => $field['key'] !== '',
            ));
        }

        unset($data['hero_image'], $data['logo_image']);

        return $data;
    }

    private function storeImages(Request $request, LandingPage $page): void
    {
        $updates = [];

        if ($request->hasFile('hero_image')) {
            $updates['hero_image'] = $request->file('hero_image')->store('landing/hero', 'public');
        }
        if ($request->hasFile('logo_image')) {
            $updates['logo_image'] = $request->file('logo_image')->store('landing/logo', 'public');
        }

        if ($updates) {
            $page->update($updates);
        }
    }
}
