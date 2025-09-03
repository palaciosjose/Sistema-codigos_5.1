<?php
namespace Shared;

require_once __DIR__ . '/../config/env_helper.php';

/**
 * Simple encryption/decryption helper using OpenSSL.
 * Requires CRYPTO_KEY defined in environment variables or .env file.
 */
class Crypto
{
    private const CIPHER = 'AES-256-CBC';
    private const PREFIX = 'ENC:';

    private static function getKey(): string
    {
        $key = env('CRYPTO_KEY');
        if (!$key) {
            throw new \RuntimeException('CRYPTO_KEY is not defined');
        }
        // Hash the key to ensure proper length
        return hash('sha256', $key, true);
    }

    /**
     * Encrypt plaintext string.
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return $plaintext;
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::getKey(), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::PREFIX . base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt a string produced by encrypt().
     */
    public static function decrypt(string $payload): string
    {
        if (!self::isEncrypted($payload)) {
            return $payload;
        }
        $data = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($data === false) {
            return '';
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::getKey(), OPENSSL_RAW_DATA, $iv);
        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Determine if value is already encrypted.
     */
    public static function isEncrypted(string $value): bool
    {
        return strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }
}
