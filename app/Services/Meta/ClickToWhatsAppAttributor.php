<?php

namespace App\Services\Meta;

use App\Models\Ad;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\LeadService;
use App\Support\AcquisitionChannel;
use Illuminate\Support\Facades\Log;

/**
 * Turns an inbound WhatsApp message into an attributed enquiry.
 *
 * A click-to-WhatsApp ad does not produce a lead form. The couple simply lands
 * in the inbox, and the only trace of the money that put them there is a
 * `referral` object riding on their very first message:
 *
 *   "referral": {
 *     "source_type": "ad", "source_id": "<ad id>", "ctwa_clid": "<click id>",
 *     "headline": "...", "body": "...", "source_url": "https://fb.me/..."
 *   }
 *
 * Without reading it, every click-to-WhatsApp enquiry looks like an organic
 * WhatsApp message and the campaign that produced it can never be credited.
 * With it, the ad, ad set and campaign are attached to the person before a
 * counsellor has even opened the thread — and because a lead is a patient in
 * this CRM, a patient file is opened at the same moment.
 */
class ClickToWhatsAppAttributor
{
    public function __construct(private LeadService $leads) {}

    /**
     * Attribute a first inbound WhatsApp message.
     *
     * @param  array  $message  The raw `messages[]` entry from the webhook.
     */
    public function attribute(Conversation $conversation, array $message, ?string $contactName = null): ?Lead
    {
        if (! config('meta.whatsapp.create_leads_from_inbound', true)) {
            return null;
        }

        // Already a known person: only backfill attribution that was missing.
        if ($conversation->lead_id) {
            $lead = Lead::find($conversation->lead_id);

            if ($lead) {
                $this->backfillReferral($lead, $message['referral'] ?? []);
            }

            return $lead;
        }

        $phone = $conversation->wa_id ?: $conversation->contact_identifier;

        if (! $phone) {
            return null;
        }

        $referral = $message['referral'] ?? [];

        try {
            $lead = $this->leads->create($this->attributesFrom($referral, $phone, $contactName, $conversation));
        } catch (\Throwable $e) {
            // A failed enquiry record must never cost the clinic the message.
            Log::error('Could not open an enquiry for an inbound WhatsApp message', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);

            return null;
        }

        $conversation->update([
            'lead_id'     => $lead->id,
            'patient_id'  => $lead->patient_id,
            'assigned_to' => $conversation->assigned_to ?: $lead->assigned_to,
        ]);

        return $lead;
    }

    /**
     * @return array<string,mixed> Intake payload for LeadService.
     */
    private function attributesFrom(array $referral, string $phone, ?string $contactName, Conversation $conversation): array
    {
        $fromAd = ($referral['source_type'] ?? null) === 'ad' && ! empty($referral['source_id']);

        $attributes = [
            'name'        => $contactName ?: $conversation->contact_name,
            'mobile'      => $phone,
            'lead_date'   => now(),
            'channel'     => $fromAd ? AcquisitionChannel::META_ADS : AcquisitionChannel::WHATSAPP,
            'source_name' => $fromAd ? 'Click-to-WhatsApp ad' : 'WhatsApp',
            'extra'       => array_filter([
                'whatsapp_referral' => $referral ?: null,
                'conversation_id'   => $conversation->id,

                // A WhatsApp profile name is whatever the person set on their
                // phone — often a nickname, a business, or nothing useful. It is
                // marked provisional so a name they later type into a form or a
                // lead ad can replace it rather than being discarded as a
                // duplicate of "what a human already recorded".
                'name_source'       => 'whatsapp_profile',
            ]),
        ];

        if ($source = $this->leadSourceFor($fromAd)) {
            $attributes['lead_source_id'] = $source;
        }

        if (! $fromAd) {
            return $attributes;
        }

        // ctwa_clid is what the Conversions API needs to credit this click.
        $attributes['external_lead_id'] = $referral['ctwa_clid'] ?? null;
        $attributes['utm_source']       = 'facebook';
        $attributes['utm_medium']       = 'ctwa';
        $attributes['utm_content']      = $referral['headline'] ?? null;

        // Walk up from the ad to its ad set and campaign, when the ad has been
        // synced into the CRM. An unsynced ad still attributes to Meta Ads.
        $ad = Ad::with('adset')->where('external_id', $referral['source_id'])->first();

        if ($ad) {
            $attributes['ad_id']       = $ad->id;
            $attributes['adset_id']    = $ad->adset_id;
            $attributes['campaign_id'] = $ad->adset?->campaign_id;
            $attributes['utm_campaign'] = $ad->adset?->campaign?->name;
        }

        return $attributes;
    }

    /**
     * A later message can carry the referral that the first one lacked (for
     * example when the thread was opened by a broadcast reply first).
     */
    private function backfillReferral(Lead $lead, array $referral): void
    {
        if ($referral === [] || ($referral['source_type'] ?? null) !== 'ad') {
            return;
        }

        // Never overwrite an attribution that is already more specific.
        if ($lead->ad_id || $lead->channel === AcquisitionChannel::META_ADS) {
            return;
        }

        $ad = Ad::with('adset')->where('external_id', $referral['source_id'] ?? '')->first();

        $lead->forceFill(array_filter([
            'channel'          => AcquisitionChannel::META_ADS,
            'external_lead_id' => $lead->external_lead_id ?: ($referral['ctwa_clid'] ?? null),
            'ad_id'            => $ad?->id,
            'adset_id'         => $ad?->adset_id,
            'campaign_id'      => $ad?->adset?->campaign_id,
        ]))->save();
    }

    private function leadSourceFor(bool $fromAd): ?int
    {
        static $cache = [];

        $slug = $fromAd ? 'meta' : 'whatsapp';

        return $cache[$slug] ??= LeadSource::where('slug', $slug)->value('id');
    }
}
