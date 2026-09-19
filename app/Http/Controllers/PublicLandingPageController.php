<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\LandingPageSubmission;
use App\Services\Chat\ConversationService;
use App\Services\LeadService;
use App\Support\AcquisitionChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public (unauthenticated) landing page rendering and form capture.
 *
 * Submissions run through LeadService so a landing-page enquiry gets the same
 * deduplication, auto-assignment and activity logging as any other lead, and
 * can optionally trigger a WhatsApp welcome template immediately.
 */
class PublicLandingPageController extends Controller
{
    public function __construct(
        private LeadService $leads,
        private ConversationService $conversations,
    ) {}

    public function show(Request $request, string $slug)
    {
        $page = LandingPage::published()->where('slug', $slug)->firstOrFail();

        // Counted with a raw increment so concurrent hits do not clobber.
        $page->increment('views');

        return view('landing.render', [
            'page'      => $page,
            'isPreview' => false,

            // Both the utm tags and the click ids are echoed into the form as
            // hidden fields; the POST would otherwise lose the query string and
            // the paid click that produced the enquiry.
            'utm'       => $this->utm($request) + $this->clickIds($request),
        ]);
    }

    public function submit(Request $request, string $slug): RedirectResponse
    {
        $page = LandingPage::published()->where('slug', $slug)->firstOrFail();

        // Public endpoint: throttle per IP to blunt bot spam.
        $key = 'lp-submit:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return back()->withInput()->withErrors([
                'form' => 'Too many submissions from this device. Please try again in a few minutes.',
            ]);
        }

        RateLimiter::hit($key, 600);

        // Honeypot: a real user never fills a hidden field.
        if ($request->filled('website')) {
            return $this->thankYou($page);
        }

        $rules = ['_token' => ['nullable']];

        foreach ($page->fields() as $field) {
            $rule = $field['required'] ?? false ? ['required'] : ['nullable'];

            $rule[] = match ($field['type']) {
                'email'    => 'email',
                'number'   => 'numeric',
                'tel'      => 'string',
                'textarea' => 'string',
                default    => 'string',
            };

            if ($field['type'] !== 'number') {
                $rule[] = 'max:1000';
            }

            $rules[$field['key']] = $rule;
        }

        $data = $request->validate($rules);

        $mobile = $this->digits($data['mobile'] ?? '');

        if (strlen($mobile) < 10) {
            return back()->withInput()->withErrors(['mobile' => 'Please enter a valid mobile number.']);
        }

        $utm    = $this->utm($request);
        $clicks = $this->clickIds($request);

        $lead = null;

        try {
            $lead = $this->leads->create([
                'name'           => $data['name'] ?? null,
                'mobile'         => $mobile,
                'email'          => $data['email'] ?? null,
                'age'            => isset($data['age']) ? (int) $data['age'] : null,
                'pincode'        => $data['pincode'] ?? null,
                'remark'         => $data['message'] ?? null,
                'lead_source_id' => $page->lead_source_id,
                'campaign_id'    => $page->campaign_id,
                'center_id'      => $page->center_id,
                'assigned_to'    => $page->assign_to,
                'utm_source'     => $utm['utm_source'] ?? null,
                'utm_medium'     => $utm['utm_medium'] ?? null,
                'utm_campaign'   => $utm['utm_campaign'] ?? null,
                'utm_term'       => $utm['utm_term'] ?? null,
                'utm_content'    => $utm['utm_content'] ?? null,

                // Click identifiers. A landing page is the one place Meta and
                // Google traffic mixes, so whichever network paid for the click
                // is recorded here and decides the lead's channel.
                'gclid'          => $clicks['gclid'] ?? null,
                'gbraid'         => $clicks['gbraid'] ?? null,
                'wbraid'         => $clicks['wbraid'] ?? null,
                'fbclid'         => $clicks['fbclid'] ?? null,

                'lead_date'      => now(),
                'source_name'    => 'Landing page: ' . $page->name,
                'extra'          => [
                    'landing_page'    => $page->slug,
                    'landing_page_id' => $page->id,
                    'answers'         => $data,
                    'click_ids'       => $clicks,
                    'referrer'        => substr((string) $request->headers->get('referer'), 0, 500),
                ],
            ]);
        } catch (\Throwable $e) {
            // The visitor must still get a confirmation; the submission row
            // preserves the data so nothing is lost if lead creation failed.
            Log::error('Landing page lead creation failed', [
                'page'  => $page->slug,
                'error' => $e->getMessage(),
            ]);
        }

        LandingPageSubmission::create([
            'landing_page_id' => $page->id,
            'lead_id'         => $lead?->id,
            'payload'         => $data,
            'utm'             => $utm + $clicks,
            'ip_address'      => $request->ip(),
            'user_agent'      => substr((string) $request->userAgent(), 0, 500),
            'referrer'        => substr((string) $request->headers->get('referer'), 0, 191),
        ]);

        $page->increment('submissions');

        $this->sendWelcome($page, $lead, $mobile, $data['name'] ?? null);

        return $this->thankYou($page);
    }

    /**
     * Fire the page's configured WhatsApp welcome template, if any.
     */
    private function sendWelcome(LandingPage $page, $lead, string $mobile, ?string $name): void
    {
        if (! $page->whatsapp_template_id) {
            return;
        }

        try {
            $template = $page->welcomeTemplate;

            if (! $template || ! $template->isApproved()) {
                return;
            }

            $conversation = $this->conversations->resolve(
                phone: $mobile,
                channel: 'whatsapp',
                contactName: $name,
                lead: $lead,
            );

            // The first variable is conventionally the contact's name.
            $params = $template->variable_count > 0
                ? array_fill(0, $template->variable_count, $name ?: 'there')
                : [];

            $this->conversations->sendTemplate($conversation, $template, $params);
        } catch (\Throwable $e) {
            Log::warning('Landing page welcome message failed', [
                'page'  => $page->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function thankYou(LandingPage $page): RedirectResponse
    {
        if ($page->redirect_url) {
            return redirect()->away($page->redirect_url);
        }

        return redirect()
            ->route('landing.show', $page->slug)
            ->with('submitted', $page->thank_you_message ?: 'Thank you! Our fertility counsellor will call you shortly.');
    }

    private function utm(Request $request): array
    {
        return array_filter($request->only(AcquisitionChannel::utmParameters()));
    }

    /**
     * gclid/gbraid/wbraid identify a Google Ads click, fbclid a Meta one. They
     * are what makes an offline conversion uploadable later, so they are kept
     * even when the visitor never fills the form.
     */
    private function clickIds(Request $request): array
    {
        return array_filter($request->only(AcquisitionChannel::clickParameters()));
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
