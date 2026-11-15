<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Field-level encryption (AES-256-GCM, authenticated) and keyed blind indexes for searching
 * encrypted values without decrypting them.
 *
 * Ciphertext layout (binary, stored in VARBINARY/BLOB columns):
 *   [1 byte version][1 byte key-id length][key id][12 byte nonce][16 byte tag][ciphertext]
 * The $context string (e.g. "patients.first_name") is bound as associated data, so a value
 * copied into another column fails to decrypt.
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const VERSION = 1;

    public static function encrypt(?string $plaintext, string $context): ?string
    {
        if ($plaintext === null) {
            return null;
        }
        $keyId = (string) Config::get('crypto.active_key_id');
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::dataKey($keyId), OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return chr(self::VERSION) . chr(strlen($keyId)) . $keyId . $nonce . $tag . $ciphertext;
    }

    public static function decrypt(?string $blob, string $context): ?string
    {
        if ($blob === null) {
            return null;
        }
        if (strlen($blob) < 2 || ord($blob[0]) !== self::VERSION) {
            throw new \RuntimeException('Unsupported ciphertext format.');
        }
        $keyIdLength = ord($blob[1]);
        $offset = 2 + $keyIdLength;
        if (strlen($blob) < $offset + 28) {
            throw new \RuntimeException('Ciphertext is truncated.');
        }
        $keyId = substr($blob, 2, $keyIdLength);
        $nonce = substr($blob, $offset, 12);
        $tag = substr($blob, $offset + 12, 16);
        $plaintext = openssl_decrypt(substr($blob, $offset + 28), self::CIPHER, self::dataKey($keyId), OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: data was altered or the key is wrong.');
        }
        return $plaintext;
    }

    /** 32-byte keyed hash of a normalised value; equal inputs give equal indexes. */
    public static function blindIndex(string $value, string $context): string
    {
        return hash_hmac('sha256', $context . '|' . self::normalize($value), self::rawKey('crypto.blind_index_key'), true);
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    /** Purpose-bound sub-key derived from the blind index key (e.g. for CSRF tokens). */
    public static function subkey(string $purpose): string
    {
        return hash_hmac('sha256', 'ksi-subkey|' . $purpose, self::rawKey('crypto.blind_index_key'), true);
    }

    public static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $text): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private static function dataKey(string $keyId): string
    {
        return self::rawKey('crypto.keys.' . $keyId);
    }

    private static function rawKey(string $configKey): string
    {
        $key = base64_decode((string) Config::get($configKey, ''), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException("Encryption key '$configKey' is missing or invalid.");
        }
        return $key;
    }
}
