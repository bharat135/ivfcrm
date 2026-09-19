<?php

namespace App\Services\Telephony;

use App\Support\SettingsRepository;
use Illuminate\Support\Facades\Log;

/**
 * Asterisk Manager Interface (AMI) client.
 *
 * Deliberately connection-per-action: PHP-FPM cannot hold a persistent AMI
 * socket across requests, and a long-lived connection would leak on every
 * fatal. Each call opens a socket, logs in, issues one action, reads the
 * response and logs off — a few milliseconds on a LAN.
 *
 * Asynchronous events (Dial, Bridge, Hangup) are NOT read here; Asterisk pushes
 * those to the CRM webhook from the dialplan instead, which survives PHP's
 * request lifecycle. See AsteriskWebhookController.
 */
class AsteriskManager
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private SettingsRepository $settings) {}

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    public function credential(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get('telephony', $key);

        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    public function host(): ?string
    {
        return $this->credential('ami_host', config('telephony.asterisk.host'));
    }

    public function port(): int
    {
        return (int) $this->credential('ami_port', config('telephony.asterisk.ami.port'));
    }

    public function username(): ?string
    {
        return $this->credential('ami_username', config('telephony.asterisk.ami.username'));
    }

    public function secret(): ?string
    {
        return $this->credential('ami_secret', config('telephony.asterisk.ami.secret'));
    }

    public function context(): string
    {
        return (string) $this->credential('context', config('telephony.asterisk.context'));
    }

    public function technology(): string
    {
        return (string) $this->credential('technology', config('telephony.asterisk.technology'));
    }

    public function trunk(): ?string
    {
        return $this->credential('trunk', config('telephony.asterisk.trunk'));
    }

    public function outboundPrefix(): string
    {
        return (string) $this->credential('outbound_prefix', config('telephony.asterisk.outbound_prefix'));
    }

    public function callerId(): string
    {
        return (string) $this->credential('caller_id', config('telephony.asterisk.caller_id'));
    }

    public function webhookToken(): ?string
    {
        return $this->credential('webhook_token', config('telephony.asterisk.webhook_token'));
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->host() && $this->username() && $this->secret());
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    private function connect(): void
    {
        if (! $this->isConfigured()) {
            throw new AsteriskException(
                'Asterisk is not configured. Add the AMI host, username and secret under Settings → Telephony.'
            );
        }

        $errno = 0;
        $error = '';

        $socket = @fsockopen(
            $this->host(),
            $this->port(),
            $errno,
            $error,
            (int) config('telephony.asterisk.ami.connect_timeout'),
        );

        if (! $socket) {
            throw new AsteriskException(
                "Could not reach Asterisk at {$this->host()}:{$this->port()} — {$error}"
            );
        }

        stream_set_timeout($socket, (int) config('telephony.asterisk.ami.read_timeout'));

        $this->socket = $socket;

        // Asterisk greets with its AMI banner before accepting actions.
        fgets($this->socket, 1024);

        $login = $this->send([
            'Action'   => 'Login',
            'Username' => $this->username(),
            'Secret'   => $this->secret(),
            'Events'   => 'off',
        ]);

        if (($login['Response'] ?? '') !== 'Success') {
            $this->disconnect();

            throw new AsteriskException(
                'Asterisk rejected the AMI login. Check the username and secret in manager.conf.',
                $login['Message'] ?? null,
                $login,
            );
        }
    }

    private function disconnect(): void
    {
        if (! $this->socket) {
            return;
        }

        // Best effort — the socket may already be gone.
        @fwrite($this->socket, "Action: Logoff\r\n\r\n");
        @fclose($this->socket);

        $this->socket = null;
    }

    /**
     * Run one AMI action against a fresh connection.
     */
    public function action(array $payload): array
    {
        try {
            $this->connect();

            $response = $this->send($payload);

            if (($response['Response'] ?? '') === 'Error') {
                throw new AsteriskException(
                    'Asterisk rejected the ' . ($payload['Action'] ?? 'action') . ' request.',
                    $response['Message'] ?? null,
                    $response,
                );
            }

            return $response;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Write an action and read its response block.
     */
    private function send(array $payload): array
    {
        $message = '';

        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Variable: may legitimately repeat.
            if (is_array($value)) {
                foreach ($value as $item) {
                    $message .= "{$key}: {$item}\r\n";
                }
                continue;
            }

            $message .= "{$key}: {$value}\r\n";
        }

        $message .= "\r\n";

        fwrite($this->socket, $message);

        return $this->read();
    }

    /**
     * Read until the blank line that terminates an AMI response block.
     */
    private function read(): array
    {
        $response = [];
        $raw = '';

        while (! feof($this->socket)) {
            $line = fgets($this->socket, 4096);

            if ($line === false) {
                break;
            }

            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                break;
            }

            $raw .= $line;
            $line = rtrim($line, "\r\n");

            if ($line === '') {
                break;
            }

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Keep repeated keys (Output: from a Command action).
                if (isset($response[$key])) {
                    $response[$key] = is_array($response[$key])
                        ? [...$response[$key], $value]
                        : [$response[$key], $value];
                    continue;
                }

                $response[$key] = $value;
            }
        }

        $response['__raw'] = $raw;

        return $response;
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    /**
     * Click-to-call: ring the agent's extension first, then dial the customer
     * and bridge the two. Ringing the agent first is what makes this work with
     * a softphone — the agent picks up locally, then hears the outbound call.
     *
     * @param  string  $extension  Agent extension, e.g. "1001"
     * @param  string  $destination  Customer number as dialled by the trunk
     * @param  array   $variables  Channel variables (used to correlate the CDR)
     */
    public function originate(
        string $extension,
        string $destination,
        array $variables = [],
        ?string $callerId = null,
        ?string $context = null,
    ): array {
        $channel = sprintf('%s/%s', $this->technology(), $extension);

        $payload = [
            'Action'   => 'Originate',
            'Channel'  => $channel,
            'Context'  => $context ?: $this->context(),
            'Exten'    => $this->outboundPrefix() . $destination,
            'Priority' => 1,
            'Timeout'  => ((int) config('telephony.asterisk.originate_timeout')) * 1000,
            'CallerID' => $callerId ?: sprintf('%s <%s>', $this->callerId(), $extension),
            // Async so AMI returns as soon as the call is queued rather than
            // blocking the web request for the whole ring cycle.
            'Async'    => 'true',
        ];

        if ($variables !== []) {
            $payload['Variable'] = array_map(
                fn ($value, $key) => "{$key}={$value}",
                $variables,
                array_keys($variables),
            );
        }

        return $this->action($payload);
    }

    /**
     * Originate straight to an application (used for playback/announcements).
     */
    public function originateToApplication(string $extension, string $application, string $data = ''): array
    {
        return $this->action([
            'Action'      => 'Originate',
            'Channel'     => sprintf('%s/%s', $this->technology(), $extension),
            'Application' => $application,
            'Data'        => $data,
            'Async'       => 'true',
            'CallerID'    => $this->callerId(),
        ]);
    }

    public function hangup(string $channel, int $cause = 16): array
    {
        return $this->action([
            'Action'  => 'Hangup',
            'Channel' => $channel,
            'Cause'   => $cause,
        ]);
    }

    /**
     * Blind transfer: move a live channel to another extension.
     */
    public function redirect(string $channel, string $extension, ?string $context = null): array
    {
        return $this->action([
            'Action'   => 'Redirect',
            'Channel'  => $channel,
            'Context'  => $context ?: $this->context(),
            'Exten'    => $extension,
            'Priority' => 1,
        ]);
    }

    /**
     * Attended transfer via the Bridge action (both legs must already exist).
     */
    public function bridgeChannels(string $channelOne, string $channelTwo): array
    {
        return $this->action([
            'Action'   => 'Bridge',
            'Channel1' => $channelOne,
            'Channel2' => $channelTwo,
            'Tone'     => 'no',
        ]);
    }

    /**
     * Start recording a live channel (when auto-record is off).
     */
    public function startRecording(string $channel, string $filename): array
    {
        return $this->action([
            'Action'  => 'MixMonitor',
            'Channel' => $channel,
            'File'    => $filename . '.' . config('telephony.asterisk.recording.format'),
        ]);
    }

    public function stopRecording(string $channel): array
    {
        return $this->action([
            'Action'  => 'StopMixMonitor',
            'Channel' => $channel,
        ]);
    }

    /**
     * Supervisor barge / whisper / listen on an agent's live channel.
     *
     * @param  string  $mode  'listen' (spy), 'whisper', 'barge'
     */
    public function chanSpy(string $supervisorExtension, string $targetChannel, string $mode = 'listen'): array
    {
        $options = match ($mode) {
            'whisper' => 'qw',
            'barge'   => 'qB',
            default   => 'q',
        };

        return $this->originateToApplication(
            $supervisorExtension,
            'ChanSpy',
            sprintf('%s,%s', $targetChannel, $options),
        );
    }

    /**
     * Send DTMF down a live channel (IVR navigation from the CRM).
     */
    public function playDtmf(string $channel, string $digit): array
    {
        return $this->action([
            'Action'  => 'PlayDTMF',
            'Channel' => $channel,
            'Digit'   => $digit,
        ]);
    }

    // -----------------------------------------------------------------
    // Introspection
    // -----------------------------------------------------------------

    /**
     * Asterisk version + uptime — the "Test connection" button.
     */
    public function ping(): array
    {
        $response = $this->action(['Action' => 'CoreSettings']);

        return [
            'version'  => $response['AsteriskVersion'] ?? 'unknown',
            'system'   => $response['CoreCurrentCalls'] ?? null,
            'manager'  => $response['AMIversion'] ?? null,
        ];
    }

    /**
     * Live channels, parsed out of `core show channels concise`.
     *
     * The concise format is stable and pipe-delimited:
     * channel!context!exten!prio!state!app!data!cid!accountcode!amaflags!duration!bridged!uniqueid
     */
    public function activeChannels(): array
    {
        $response = $this->action([
            'Action'  => 'Command',
            'Command' => 'core show channels concise',
        ]);

        $output = $response['Output'] ?? ($response['__raw'] ?? '');
        $lines = is_array($output) ? $output : explode("\n", (string) $output);

        $channels = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '!')) {
                continue;
            }

            $parts = explode('!', $line);

            if (count($parts) < 13) {
                continue;
            }

            $channels[] = [
                'channel'    => $parts[0],
                'context'    => $parts[1],
                'extension'  => $parts[2],
                'state'      => $parts[4],
                'application'=> $parts[5],
                'caller_id'  => $parts[7],
                'duration'   => (int) $parts[10],
                'bridged'    => $parts[11] ?: null,
                'uniqueid'   => $parts[12],
            ];
        }

        return $channels;
    }

    /**
     * Registration state of every configured endpoint, so the settings screen
     * can show which agents actually have a softphone online.
     *
     * @return array<string, array{status:string, address:?string}>
     */
    public function endpointStates(): array
    {
        $command = $this->technology() === 'PJSIP'
            ? 'pjsip show endpoints'
            : 'sip show peers';

        try {
            $response = $this->action(['Action' => 'Command', 'Command' => $command]);
        } catch (AsteriskException $e) {
            Log::debug('Asterisk endpoint state lookup failed', ['error' => $e->getMessage()]);

            return [];
        }

        $output = $response['Output'] ?? ($response['__raw'] ?? '');
        $lines  = is_array($output) ? $output : explode("\n", (string) $output);

        $states = [];

        foreach ($lines as $line) {
            // PJSIP:  Endpoint:  1001/1001   Not in use  0 of inf
            if (preg_match('/^\s*Endpoint:\s+(\S+?)(?:\/\S+)?\s+(.+?)\s+\d+ of/', $line, $m)) {
                $states[$m[1]] = ['status' => trim($m[2]), 'address' => null];
                continue;
            }

            // chan_sip: 1001/1001  10.0.0.5  D  Yes  Yes  5060  OK (12 ms)
            if (preg_match('/^(\d+)\/\S+\s+(\S+)\s+.*?(OK|UNREACHABLE|UNKNOWN|Unmonitored)/', $line, $m)) {
                $states[$m[1]] = ['status' => $m[3], 'address' => $m[2]];
            }
        }

        return $states;
    }

    /**
     * Look up the live channel belonging to an extension — needed to hang up
     * or transfer a call the CRM did not itself originate.
     */
    public function findChannelForExtension(string $extension): ?array
    {
        $needle = strtolower($this->technology() . '/' . $extension);

        foreach ($this->activeChannels() as $channel) {
            if (str_starts_with(strtolower($channel['channel']), $needle)) {
                return $channel;
            }
        }

        return null;
    }
}
