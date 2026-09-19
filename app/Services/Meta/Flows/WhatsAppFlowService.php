<?php

namespace App\Services\Meta\Flows;

use App\Models\ChannelAccount;
use App\Models\WhatsappFlow;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Meta Flows API: create, upload JSON, validate, publish, preview, send.
 *
 * A Flow's lifecycle is deliberately two-step — the Flow object is created
 * first and its JSON uploaded as an *asset* afterwards, so a draft can be
 * revised repeatedly before publishing. Publishing is one-way: a published
 * Flow's JSON can no longer be changed, only deprecated and replaced.
 */
class WhatsAppFlowService
{
    public function __construct(
        private MetaClient $client,
        private FlowEncryption $encryption,
    ) {}

    private function wabaId(?ChannelAccount $account = null): string
    {
        $id = $account?->credentials['waba_id'] ?? null;
        $id = $id ?: $this->client->wabaId();

        if (! $id) {
            throw new MetaException('WhatsApp Business Account ID (WABA ID) is not configured.');
        }

        return $id;
    }

    private function phoneNumberId(?ChannelAccount $account = null): string
    {
        $id = $account?->credentials['phone_number_id'] ?? null;
        $id = $id ?: $this->client->phoneNumberId();

        if (! $id) {
            throw new MetaException('WhatsApp phone number ID is not configured.');
        }

        return $id;
    }

    private function token(?ChannelAccount $account = null): ?string
    {
        return $account?->credentials['access_token'] ?? null;
    }

    public function isConfigured(): bool
    {
        return $this->client->isWhatsAppConfigured() && (bool) $this->client->wabaId();
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    public const FIELDS = 'id,name,categories,status,validation_errors,json_version,data_api_version,'
        . 'endpoint_uri,preview,whatsapp_business_account,application';

    public function listFlows(?ChannelAccount $account = null): array
    {
        return $this->client->paginate(
            $this->wabaId($account) . '/flows',
            ['fields' => self::FIELDS],
            maxPages: 10,
            token: $this->token($account),
        );
    }

    public function getFlow(string $flowId, ?ChannelAccount $account = null): array
    {
        return $this->client->get($flowId, ['fields' => self::FIELDS], $this->token($account));
    }

    /**
     * Create the Flow object. The JSON is uploaded separately.
     */
    public function createFlow(
        string $name,
        array $categories,
        ?string $endpointUri = null,
        ?ChannelAccount $account = null,
    ): array {
        $payload = [
            'name'       => $name,
            'categories' => json_encode(array_values($categories)),
        ];

        // Declaring an endpoint switches the Flow into data-exchange mode.
        if ($endpointUri) {
            $payload['endpoint_uri'] = $endpointUri;
        }

        return $this->client->post($this->wabaId($account) . '/flows', $payload, $this->token($account));
    }

    public function updateFlowMetadata(string $flowId, array $data, ?ChannelAccount $account = null): array
    {
        $payload = array_filter([
            'name'         => $data['name'] ?? null,
            'categories'   => isset($data['categories']) ? json_encode(array_values($data['categories'])) : null,
            'endpoint_uri' => $data['endpoint_uri'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->client->post($flowId, $payload, $this->token($account));
    }

    /**
     * Upload the Flow JSON. Meta validates on upload and returns
     * `validation_errors`, which is the only way to find out a screen is
     * malformed short of publishing.
     *
     * @return array{success:bool, validation_errors:array}
     */
    public function uploadFlowJson(string $flowId, array|string $flowJson, ?ChannelAccount $account = null): array
    {
        $json = is_string($flowJson)
            ? $flowJson
            : json_encode($flowJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The Graph asset edge is multipart, so the JSON has to hit disk first.
        $temp = tempnam(sys_get_temp_dir(), 'flow') . '.json';
        file_put_contents($temp, $json);

        try {
            $response = $this->client->upload(
                $flowId . '/assets',
                $temp,
                'file',
                ['name' => 'flow.json', 'asset_type' => 'FLOW_JSON'],
                $this->token($account),
            );
        } finally {
            @unlink($temp);
        }

        return [
            'success'           => (bool) ($response['success'] ?? false),
            'validation_errors' => $response['validation_errors'] ?? [],
        ];
    }

    public function assets(string $flowId, ?ChannelAccount $account = null): array
    {
        return $this->client->get($flowId . '/assets', [], $this->token($account))['data'] ?? [];
    }

    /**
     * Publish. Irreversible: a published Flow's JSON is frozen, and the only
     * way forward is to deprecate it and publish a replacement.
     */
    public function publish(string $flowId, ?ChannelAccount $account = null): array
    {
        return $this->client->post($flowId . '/publish', [], $this->token($account));
    }

    public function deprecate(string $flowId, ?ChannelAccount $account = null): array
    {
        return $this->client->post($flowId . '/deprecate', [], $this->token($account));
    }

    /**
     * Only a DRAFT flow can be deleted; published ones must be deprecated.
     */
    public function deleteFlow(string $flowId, ?ChannelAccount $account = null): array
    {
        return $this->client->delete($flowId, [], $this->token($account));
    }

    /**
     * A short-lived web URL that renders the Flow exactly as WhatsApp will.
     */
    public function preview(string $flowId, bool $invalidate = false, ?ChannelAccount $account = null): ?string
    {
        $response = $this->client->get(
            $flowId,
            ['fields' => 'preview.invalidate(' . ($invalidate ? 'true' : 'false') . ')'],
            $this->token($account),
        );

        return $response['preview']['preview_url'] ?? null;
    }

    // -----------------------------------------------------------------
    // Endpoint key registration
    // -----------------------------------------------------------------

    /**
     * Register the business public key so Meta can encrypt endpoint calls.
     * Without this, an endpoint Flow cannot run at all.
     */
    public function uploadPublicKey(?ChannelAccount $account = null): array
    {
        $publicKey = $this->encryption->publicKey();

        if (! $publicKey) {
            throw new MetaException('Generate a Flow endpoint key pair before uploading it to Meta.');
        }

        return $this->client->post(
            $this->phoneNumberId($account) . '/whatsapp_business_encryption',
            ['business_public_key' => $publicKey],
            $this->token($account),
        );
    }

    /**
     * What Meta currently holds, so a mismatch can be spotted before a flow
     * starts failing with 421s in production.
     */
    public function registeredPublicKey(?ChannelAccount $account = null): array
    {
        $response = $this->client->get(
            $this->phoneNumberId($account) . '/whatsapp_business_encryption',
            [],
            $this->token($account),
        );

        return $response['data'][0] ?? $response;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    /**
     * Send a published Flow as an interactive message.
     *
     * The flow_token is ours: Meta echoes it on every endpoint call and on the
     * final reply, and it is the only handle tying those back to a lead.
     *
     * @param  string  $mode  'navigate' for a static flow, 'data_exchange' when
     *                        the first screen's data comes from our endpoint.
     */
    public function sendFlow(
        string $to,
        WhatsappFlow $flow,
        string $flowToken,
        array $options = [],
        ?ChannelAccount $account = null,
    ): array {
        $mode = $options['mode'] ?? ($flow->isEndpointFlow() ? 'data_exchange' : 'navigate');

        $parameters = [
            'flow_message_version' => '3',
            'flow_token'           => $flowToken,
            'flow_id'              => $flow->external_id,
            'flow_cta'             => $options['cta'] ?? 'Open',
            'flow_action'          => $mode,
        ];

        // `navigate` must name the screen to open; `data_exchange` must not —
        // the endpoint's INIT response decides which screen comes first.
        if ($mode === 'navigate') {
            $parameters['flow_action_payload'] = array_filter([
                'screen' => $options['screen'] ?? $flow->firstScreen(),
                'data'   => $options['data'] ?? null,
            ], fn ($v) => $v !== null && $v !== []);
        }

        $interactive = [
            'type'   => 'flow',
            'body'   => ['text' => $options['body'] ?? $flow->name],
            'action' => ['name' => 'flow', 'parameters' => $parameters],
        ];

        if (! empty($options['header'])) {
            $interactive['header'] = ['type' => 'text', 'text' => $options['header']];
        }
        if (! empty($options['footer'])) {
            $interactive['footer'] = ['text' => $options['footer']];
        }

        return $this->client->post(
            $this->phoneNumberId($account) . '/messages',
            [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => preg_replace('/\D/', '', $to),
                'type'              => 'interactive',
                'interactive'       => $interactive,
            ],
            $this->token($account),
        );
    }

    public static function generateFlowToken(): string
    {
        return 'flw_' . Str::lower(Str::random(28));
    }

    // -----------------------------------------------------------------
    // Sync
    // -----------------------------------------------------------------

    /**
     * Pull the WABA's flows into the local mirror. Idempotent on external_id.
     */
    public function sync(?ChannelAccount $account = null): int
    {
        $count = 0;

        foreach ($this->listFlows($account) as $remote) {
            $existing = WhatsappFlow::withTrashed()->where('external_id', $remote['id'])->first();

            $attributes = [
                'name'              => $remote['name'] ?? 'Untitled flow',
                'status'            => $remote['status'] ?? 'DRAFT',
                'categories'        => $remote['categories'] ?? [],
                'json_version'      => $remote['json_version'] ?? config('whatsapp_flows.json_version'),
                'data_api_version'  => $remote['data_api_version'] ?? null,
                'endpoint_uri'      => $remote['endpoint_uri'] ?? null,
                'preview_url'       => $remote['preview']['preview_url'] ?? null,
                'preview_expires_at'=> isset($remote['preview']['expires_at'])
                    ? \Illuminate\Support\Carbon::parse($remote['preview']['expires_at']) : null,
                'validation_errors' => $remote['validation_errors'] ?? null,
                'synced_at'         => now(),
                'sync_error'        => null,
            ];

            if ($existing) {
                $existing->fill($attributes)->save();
            } else {
                WhatsappFlow::create($attributes + [
                    'external_id'      => $remote['id'],
                    'channel_account_id' => $account?->id,
                    'slug'             => Str::slug($remote['name'] ?? 'flow') . '-' . Str::lower(Str::random(4)),
                    'auto_create_lead' => true,
                ]);
            }

            $count++;
        }

        Log::info('WhatsApp flows synced', ['count' => $count]);

        return $count;
    }
}
