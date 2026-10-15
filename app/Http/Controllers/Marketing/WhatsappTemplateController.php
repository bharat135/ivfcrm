<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Models\WhatsappTemplate;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaSyncService;
use App\Services\Meta\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * The WABA template library.
 *
 * Templates are the only way to message a contact outside the 24-hour window,
 * so the CRM keeps a synced mirror and lets a manager submit new ones for
 * Meta review without leaving the app.
 */
class WhatsappTemplateController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private MetaSyncService $sync,
        private MetaClient $client,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $source = (string) $request->input('source', '');

            $query = WhatsappTemplate::with('channelAccount:id,name,provider')
                ->when($source === 'shared', fn ($q) => $q->whereNull('channel_account_id'))
                ->when(ctype_digit($source), fn ($q) => $q->where('channel_account_id', (int) $source))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
                ->when($request->filled('language'), fn ($q) => $q->where('language', $request->string('language')))
                ->when($request->filled('has_variables'), fn ($q) => $request->boolean('has_variables')
                    ? $q->where('variable_count', '>', 0) : $q->where('variable_count', 0));

            return DataTables::of($query)
                ->addColumn('provider', fn (WhatsappTemplate $t) => $this->providerBadge($t->channelAccount))
                ->addColumn('status_badge', fn (WhatsappTemplate $t) =>
                    '<span class="badge bg-' . $t->statusBadge() . '">' . e($t->status) . '</span>')
                ->addColumn('preview', fn (WhatsappTemplate $t) =>
                    '<span class="text-muted small">' . e(\Illuminate\Support\Str::limit($t->body_text, 90)) . '</span>')
                ->addColumn('vars', fn (WhatsappTemplate $t) => $t->variable_count ?: '—')
                ->addColumn('action', fn (WhatsappTemplate $t) => view('marketing.templates._actions', ['template' => $t])->render())
                ->rawColumns(['provider', 'status_badge', 'preview', 'action'])
                ->make(true);
        }

        $accounts = ChannelAccount::where('channel', 'whatsapp')->orderByDesc('is_active')->orderByDesc('is_default')->orderBy('name')->get();
        $default  = $accounts->first(fn (ChannelAccount $a) => $a->is_active && $a->is_default);

        // Each WABA keeps its own templates, so the page works per provider:
        // the direct Meta setup under Settings → Integrations ("shared") and
        // every WhatsApp channel account (Meta or 1automations).
        $sources = collect();

        if ($this->client->wabaId()) {
            $sources->put('shared', 'Meta Cloud API · Settings → Integrations');
        }

        foreach ($accounts as $account) {
            $sources->put((string) $account->id, $account->name . ' · ' . $this->providerLabel($account)
                . ($account->is_active && $account->is_default ? ' (default sender)' : '')
                . ($account->is_active ? '' : ' (inactive)'));
        }

        return view('marketing.templates.index', [
            'accounts'   => $accounts,
            'sources'    => $sources,
            'selectedSource' => $default ? (string) $default->id : ($sources->keys()->first() ?? ''),
            'configured' => $this->client->wabaId() !== null
                || $accounts->contains(fn (ChannelAccount $a) => ! empty($a->credentials['waba_id'])),
            'categories' => ['MARKETING' => 'Marketing', 'UTILITY' => 'Utility', 'AUTHENTICATION' => 'Authentication'],
            'languages'  => $this->languages(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $account = $request->filled('channel_account_id')
            ? ChannelAccount::find($request->integer('channel_account_id'))
            : null;

        try {
            $count = $this->sync->syncTemplates($account);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Synced {$count} template(s) from your WhatsApp Business Account.",
        ]);
    }

    /**
     * Submit a new template for Meta review.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language'  => ['required', 'string', 'max:20'],
            'category'  => ['required', Rule::in(['MARKETING', 'UTILITY', 'AUTHENTICATION'])],
            'header_format' => ['nullable', Rule::in(['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header_text'   => ['nullable', 'string', 'max:60'],
            'body'      => ['required', 'string', 'max:1024'],
            'footer'    => ['nullable', 'string', 'max:60'],
            'buttons'   => ['nullable', 'array', 'max:3'],
            'buttons.*.type' => ['required_with:buttons', Rule::in(['QUICK_REPLY', 'URL', 'PHONE_NUMBER'])],
            'buttons.*.text' => ['required_with:buttons', 'string', 'max:25'],
            'buttons.*.value'=> ['nullable', 'string', 'max:2000'],
            'example_params' => ['nullable', 'array'],
            'channel_account_id' => ['nullable', 'exists:channel_accounts,id'],
        ], [
            'name.regex' => 'Template name may only contain lowercase letters, numbers and underscores.',
        ]);

        $components = $this->buildComponents($data);

        $account = ! empty($data['channel_account_id'])
            ? ChannelAccount::find($data['channel_account_id'])
            : null;

        try {
            $response = $this->whatsapp->createTemplate([
                'name'       => $data['name'],
                'language'   => $data['language'],
                'category'   => $data['category'],
                'components' => json_encode($components),
            ], $account);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $extracted = WhatsappTemplate::extractFromComponents($components);

        $template = WhatsappTemplate::updateOrCreate(
            [
                'channel_account_id' => $account?->id,
                'name'               => $data['name'],
                'language'           => $data['language'],
            ],
            [
                'category'       => $data['category'],
                'status'         => $response['status'] ?? 'PENDING',
                'external_id'    => $response['id'] ?? null,
                'components'     => $components,
                'body_text'      => $extracted['body_text'],
                'variable_count' => $extracted['variable_count'],
                'header_format'  => $extracted['header_format'],
                'synced_at'      => now(),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Template submitted to Meta. Review usually completes within a few minutes to 24 hours.',
            'id'      => $template->id,
        ]);
    }

    public function show(WhatsappTemplate $template): JsonResponse
    {
        return response()->json([
            'template'   => $template,
            'components' => $template->components,
            'preview'    => $template->body_text,
        ]);
    }

    public function destroy(WhatsappTemplate $template): JsonResponse
    {
        try {
            $this->whatsapp->deleteTemplate($template->name, $template->channelAccount);
        } catch (MetaException $e) {
            // Already gone upstream is fine; anything else should surface.
            if (! str_contains(strtolower($e->getMessage()), 'does not exist')) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $template->delete();

        return response()->json(['success' => true, 'message' => 'Template deleted.']);
    }

    /**
     * Build Meta's component array from the simplified builder form.
     */
    private function buildComponents(array $data): array
    {
        $components = [];

        $headerFormat = $data['header_format'] ?? 'NONE';

        if ($headerFormat === 'TEXT' && ! empty($data['header_text'])) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $data['header_text']];
        } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $components[] = ['type' => 'HEADER', 'format' => $headerFormat];
        }

        $body = ['type' => 'BODY', 'text' => $data['body']];

        // Meta requires sample values for every {{n}} placeholder.
        preg_match_all('/\{\{(\d+)\}\}/', $data['body'], $matches);

        if (! empty($matches[1])) {
            $count    = max(array_map('intval', $matches[1]));
            $provided = array_values($data['example_params'] ?? []);
            $examples = [];

            for ($i = 0; $i < $count; $i++) {
                $examples[] = $provided[$i] ?? 'Sample';
            }

            $body['example'] = ['body_text' => [$examples]];
        }

        $components[] = $body;

        if (! empty($data['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $data['footer']];
        }

        if (! empty($data['buttons'])) {
            $buttons = [];

            foreach ($data['buttons'] as $button) {
                $entry = ['type' => $button['type'], 'text' => $button['text']];

                if ($button['type'] === 'URL') {
                    $entry['url'] = $button['value'] ?? '';
                }
                if ($button['type'] === 'PHONE_NUMBER') {
                    $entry['phone_number'] = $button['value'] ?? '';
                }

                $buttons[] = $entry;
            }

            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    private function providerLabel(?ChannelAccount $account): string
    {
        return $account?->isThirdPartyWhatsApp() ? '1automations' : 'Meta Cloud API';
    }

    private function providerBadge(?ChannelAccount $account): string
    {
        $third = $account?->isThirdPartyWhatsApp();

        return '<span class="badge ' . ($third ? 'bg-info-subtle text-info-emphasis' : 'bg-primary-subtle text-primary-emphasis') . '">'
            . e($this->providerLabel($account)) . '</span>'
            . '<div class="small text-muted text-truncate" style="max-width:160px">' . e($account?->name ?? 'Settings → Integrations') . '</div>';
    }

    private function languages(): array
    {
        return [
            'en'    => 'English',
            'en_US' => 'English (US)',
            'en_GB' => 'English (UK)',
            'hi'    => 'Hindi',
            'gu'    => 'Gujarati',
            'mr'    => 'Marathi',
            'bn'    => 'Bengali',
            'ta'    => 'Tamil',
            'te'    => 'Telugu',
            'kn'    => 'Kannada',
            'ml'    => 'Malayalam',
            'pa'    => 'Punjabi',
        ];
    }
}
