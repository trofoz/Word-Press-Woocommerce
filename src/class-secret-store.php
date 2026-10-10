<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Encrypts merchant credentials with a key supplied outside the database. */
final class SecretStore
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 'v1';

    public static function available(): bool
    {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && defined('WFPMS_MASTER_KEY')
            && is_string(WFPMS_MASTER_KEY)
            && preg_match('/\A[a-fA-F0-9]{64}\z/', WFPMS_MASTER_KEY) === 1
            && defined('WFPMS_MASTER_KEY_ID')
            && is_string(WFPMS_MASTER_KEY_ID)
            && preg_match('/\A[a-zA-Z0-9._-]{1,32}\z/', WFPMS_MASTER_KEY_ID) === 1;
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || !self::available()) {
            throw new \RuntimeException('Merchant credential encryption is unavailable.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            hex2bin(WFPMS_MASTER_KEY),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'wfpms-merchant-secret:v1',
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('Merchant credential encryption failed.');
        }

        return self::VERSION . ':' . WFPMS_MASTER_KEY_ID . ':' . base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): ?string
    {
        if (!self::available()) {
            return null;
        }
        $parts = explode(':', $encoded, 3);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION || !hash_equals(WFPMS_MASTER_KEY_ID, $parts[1])) {
            return null;
        }
        $raw = base64_decode($parts[2], true);
        if (!is_string($raw) || strlen($raw) < 29) {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            hex2bin(WFPMS_MASTER_KEY),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            'wfpms-merchant-secret:v1'
        );
        return is_string($plaintext) ? $plaintext : null;
    }
}
