<?php
/**
 * Encryption & Decryption Functions
 * 
 * AES-256-CBC encryption for API keys and sensitive settings.
 * Uses ENCRYPTION_KEY from environment for key derivation.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

/**
 * Encrypt a string using AES-256-CBC
 */
function encrypt(string $plaintext): string
{
    if (empty(ENCRYPTION_KEY)) {
        throw new RuntimeException('ENCRYPTION_KEY not configured');
    }

    $key = hash('sha256', ENCRYPTION_KEY, true);
    $iv = random_bytes(16);
    $cipher = 'aes-256-cbc';

    $encrypted = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) {
        throw new RuntimeException('Encryption failed');
    }

    // Prepend IV to ciphertext, then base64 encode
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt an AES-256-CBC encrypted string
 */
function decrypt(string $ciphertext): string|false
{
    if (empty(ENCRYPTION_KEY)) {
        throw new RuntimeException('ENCRYPTION_KEY not configured');
    }

    $key = hash('sha256', ENCRYPTION_KEY, true);
    $cipher = 'aes-256-cbc';

    $data = base64_decode($ciphertext, true);
    if ($data === false || strlen($data) < 17) {
        return false;
    }

    // Extract IV (first 16 bytes) and ciphertext
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);

    $decrypted = openssl_decrypt($encrypted, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    return $decrypted;
}

/**
 * Generate a signed payload for communication with WordPress plugin.
 * Uses HMAC-SHA256 with a shared secret.
 */
function signPayload(array $data, string $secret): string
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash_hmac('sha256', $json, $secret);
}

/**
 * Verify a signed payload from WordPress plugin
 */
function verifyPayload(string $payload, string $signature, string $secret): bool
{
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

/**
 * Encrypt data for transit between hub and plugin.
 * Uses a derived key from the site's API key.
 */
function encryptTransit(array $data, string $apiKey): string
{
    $key = hash('sha256', $apiKey . ':transit', true);
    $iv = random_bytes(16);
    $cipher = 'aes-256-cbc';

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $encrypted = openssl_encrypt($json, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) {
        throw new RuntimeException('Transit encryption failed');
    }

    $payload = base64_encode($iv . $encrypted);
    $signature = hash_hmac('sha256', $payload, $apiKey);

    return json_encode([
        'payload'   => $payload,
        'signature' => $signature,
        'timestamp' => time(),
    ]);
}

/**
 * Decrypt data received from WordPress plugin
 */
function decryptTransit(string $json, string $apiKey): array|false
{
    $envelope = json_decode($json, true);
    if (!$envelope || empty($envelope['payload']) || empty($envelope['signature'])) {
        return false;
    }

    // Verify signature
    $expectedSig = hash_hmac('sha256', $envelope['payload'], $apiKey);
    if (!hash_equals($expectedSig, $envelope['signature'])) {
        return false;
    }

    // Check timestamp (5 minute window)
    if (isset($envelope['timestamp'])) {
        $age = abs(time() - (int)$envelope['timestamp']);
        if ($age > 300) {
            return false;
        }
    }

    // Decrypt
    $key = hash('sha256', $apiKey . ':transit', true);
    $cipher = 'aes-256-cbc';

    $data = base64_decode($envelope['payload'], true);
    if ($data === false || strlen($data) < 17) {
        return false;
    }

    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);

    $decrypted = openssl_decrypt($encrypted, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        return false;
    }

    return json_decode($decrypted, true) ?: false;
}

/**
 * Generate ENCRYPTION_KEY value for .env
 */
function generateEncryptionKey(): string
{
    return bin2hex(random_bytes(32)); // 64 hex chars
}
