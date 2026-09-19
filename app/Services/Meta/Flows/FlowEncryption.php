<?php

namespace App\Services\Meta\Flows;

use App\Support\SettingsRepository;
use RuntimeException;

/**
 * Request/response encryption for WhatsApp Flow endpoints.
 *
 * Meta encrypts every endpoint call and expects an encrypted reply. The scheme:
 *
 *   1. The business uploads an RSA-2048 public key to Meta once.
 *   2. Meta generates a random 128-bit AES key per request, encrypts it with
 *      that public key (RSA-OAEP, SHA-256) and sends it as `encrypted_aes_key`.
 *   3. The body itself is AES-128-GCM, sent as `encrypted_flow_data` with its
 *      `initial_vector`. The GCM tag is the last 16 bytes of the ciphertext.
 *   4. The response is encrypted with the SAME AES key but an IV of every bit
 *      of the original flipped. Reusing the IV unchanged would be a nonce
 *      reuse under the same key, which breaks GCM outright — hence the flip.
 *
 * The private key never leaves the server. It is stored encrypted in settings.
 */
class FlowEncryption
{
    private const TAG_LENGTH = 16;
    private const CIPHER = 'aes-128-gcm';

    public function __construct(private SettingsRepository $settings) {}

    // -----------------------------------------------------------------
    // Key management
    // -----------------------------------------------------------------

    /**
     * Generate an RSA-2048 keypair and store the private half encrypted.
     *
     * @return array{public:string, private:string}
     */
    public function generateKeyPair(?string $passphrase = null): array
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
        ];

        // Without a readable openssl.cnf, openssl_pkey_new() fails outright.
        // Windows/XAMPP PHP ships with OPENSSL_CONF unset, so the path is
        // resolved here rather than assumed.
        if ($config = $this->opensslConfig()) {
            $options['config'] = $config;
        }

        $resource = openssl_pkey_new($options);

        if ($resource === false) {
            throw new RuntimeException(
                'Could not generate an RSA key pair: ' . $this->opensslErrors()
                . ' If this is a Windows host, set OPENSSL_CONF or whatsapp_flows.openssl_conf '
                . 'to the path of openssl.cnf.'
            );
        }

        // The same config has to be passed to the export.
        $exported = isset($options['config'])
            ? openssl_pkey_export($resource, $privateKey, $passphrase ?: null, ['config' => $options['config']])
            : openssl_pkey_export($resource, $privateKey, $passphrase ?: null);

        if (! $exported) {
            throw new RuntimeException('Could not export the generated private key: ' . $this->opensslErrors());
        }

        $details = openssl_pkey_get_details($resource);
        $publicKey = $details['key'] ?? null;

        if (! $privateKey || ! $publicKey) {
            throw new RuntimeException('RSA key generation produced an unusable key.');
        }

        $this->settings->setMany([
            'flow_private_key'        => $privateKey,
            'flow_private_passphrase' => $passphrase ?: '',
            'flow_public_key'         => $publicKey,
        ], 'meta');

        return ['public' => $publicKey, 'private' => $privateKey];
    }

    /**
     * Locate an openssl.cnf. Returns null when OpenSSL can find its own, which
     * is the normal case on Linux.
     *
     * Public because anything else on this host that calls openssl_pkey_new()
     * needs the same path; duplicating the probe is how the two drift apart.
     */
    public function opensslConfig(): ?string
    {
        $configured = config('whatsapp_flows.openssl_conf');

        if ($configured && is_readable($configured)) {
            return $configured;
        }

        $env = getenv('OPENSSL_CONF');

        if ($env && is_readable($env)) {
            return $env;
        }

        foreach ([
            // XAMPP on Windows ships these but sets neither env var.
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/openssl/openssl.cnf',
            'C:/xampp/apache/bin/openssl.cnf',
            // Common Linux locations, for hosts with an unusual prefix.
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/etc/pki/tls/openssl.cnf',
        ] as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Drain OpenSSL's error queue into one readable line. */
    private function opensslErrors(): string
    {
        $errors = [];

        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        return $errors ? implode('; ', array_unique($errors)) : 'no OpenSSL error reported';
    }

    public function publicKey(): ?string
    {
        return $this->settings->get('meta', 'flow_public_key') ?: null;
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $pem = $this->settings->get('meta', 'flow_private_key');

        if (! $pem) {
            throw new RuntimeException(
                'No Flow endpoint private key. Generate one under Settings → Integrations before enabling endpoint flows.'
            );
        }

        $passphrase = $this->settings->get('meta', 'flow_private_passphrase') ?: null;

        $key = openssl_pkey_get_private($pem, $passphrase);

        if ($key === false) {
            throw new RuntimeException('The stored Flow private key could not be read: ' . openssl_error_string());
        }

        return $key;
    }

    public function hasKeyPair(): bool
    {
        return (bool) ($this->settings->get('meta', 'flow_private_key')
            && $this->settings->get('meta', 'flow_public_key'));
    }

    /**
     * Fingerprint for display, so an admin can confirm the key Meta holds is
     * the key this server has without ever revealing the private half.
     */
    public function publicKeyFingerprint(): ?string
    {
        $public = $this->publicKey();

        if (! $public) {
            return null;
        }

        return strtoupper(implode(':', str_split(substr(hash('sha256', $public), 0, 32), 4)));
    }

    // -----------------------------------------------------------------
    // Request / response
    // -----------------------------------------------------------------

    /**
     * Decrypt an endpoint request.
     *
     * @return array{payload:array, aesKey:string, iv:string}
     */
    public function decryptRequest(array $body): array
    {
        foreach (['encrypted_aes_key', 'encrypted_flow_data', 'initial_vector'] as $field) {
            if (empty($body[$field])) {
                throw new FlowDecryptionException("Missing '{$field}' in the Flow endpoint request.");
            }
        }

        $encryptedAesKey = base64_decode($body['encrypted_aes_key'], true);
        $encryptedData   = base64_decode($body['encrypted_flow_data'], true);
        $iv              = base64_decode($body['initial_vector'], true);

        if ($encryptedAesKey === false || $encryptedData === false || $iv === false) {
            throw new FlowDecryptionException('Flow endpoint request contained malformed base64.');
        }

        // The AES key is wrapped with RSA-OAEP / SHA-256.
        $aesKey = '';
        $decrypted = openssl_private_decrypt(
            $encryptedAesKey,
            $aesKey,
            $this->privateKey(),
            OPENSSL_PKCS1_OAEP_PADDING,
        );

        if (! $decrypted) {
            // Meta returns 421 for this specific case so the client refreshes
            // the public key rather than failing the whole flow.
            throw new FlowDecryptionException(
                'Could not unwrap the AES key — the public key Meta holds does not match this server.',
                keyMismatch: true,
            );
        }

        // GCM sends the auth tag appended to the ciphertext.
        $tag        = substr($encryptedData, -self::TAG_LENGTH);
        $ciphertext = substr($encryptedData, 0, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new FlowDecryptionException('Flow payload failed its authentication tag check.');
        }

        $payload = json_decode($plaintext, true);

        if (! is_array($payload)) {
            throw new FlowDecryptionException('Flow payload was not valid JSON.');
        }

        return ['payload' => $payload, 'aesKey' => $aesKey, 'iv' => $iv];
    }

    /**
     * Encrypt a response with the request's AES key and the flipped IV.
     *
     * Reusing the IV would repeat a (key, nonce) pair under GCM, which leaks
     * the authentication key. Meta's protocol flips every bit instead.
     */
    public function encryptResponse(array $response, string $aesKey, string $iv): string
    {
        $flippedIv = $this->flipIv($iv);

        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($response),
            self::CIPHER,
            $aesKey,
            OPENSSL_RAW_DATA,
            $flippedIv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Could not encrypt the Flow endpoint response.');
        }

        // Meta expects a bare base64 string, not JSON.
        return base64_encode($ciphertext . $tag);
    }

    /**
     * Bitwise NOT of every byte of the IV.
     */
    private function flipIv(string $iv): string
    {
        $flipped = '';

        for ($i = 0, $length = strlen($iv); $i < $length; $i++) {
            $flipped .= chr(~ord($iv[$i]) & 0xFF);
        }

        return $flipped;
    }
}
