<?php
declare(strict_types=1);

namespace App\Core;

/**
 * v1.3.0-beta.2 安全加固 (CVE-2026-MR-013, CWE-312): at-rest encryption for
 * stored credentials (storage AccessKey/SecretKey, etc.).
 *
 * Threat model: a database leak (SQL dump, misconfigured backup) must NOT
 * hand over usable cloud credentials. Secrets are sealed with AES-256-GCM
 * under a key that lives OUTSIDE the database, in config/credential_key.php.
 * That file is generated on first use and written as plain PHP, so even a
 * direct web request executes it instead of revealing its contents (the same
 * trust model as config/database.php).
 *
 * Compatibility: values without the "enc:v1:" prefix are returned as-is, so
 * pre-existing plaintext configs keep working and get re-sealed transparently
 * the next time the profile is saved.
 */
final class CredentialCipher
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private static ?string $key = null;
    /** True when the key file could not be created/read — degrade to plaintext. */
    private static bool $unavailable = false;

    /** Fields inside a storage profile config that hold credentials. */
    public static function isSecretField(string $key): bool
    {
        $k = strtolower($key);
        return $k === 'secret'
            || $k === 'password'
            || $k === 'token'
            || str_ends_with($k, 'secret')
            || str_ends_with($k, '_secret')
            || str_ends_with($k, 'secret_key');
    }

    /** Seal one value. Non-secret/empty inputs and unavailable-key environments pass through. */
    public static function encrypt(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }
        $key = self::loadKey();
        if ($key === null) {
            return $plain; // degraded mode — same behaviour as before this class
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    /** Open one value. Anything that is not a sealed blob returns unchanged. */
    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored; // legacy plaintext — transparently compatible
        }
        $key = self::loadKey();
        if ($key === null) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) { // 12 nonce + 16 tag minimum
            return '';
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? '' : $plain;
    }

    /** Apply encrypt() to every secret field of a config array. */
    public static function sealConfig(array $config): array
    {
        foreach ($config as $k => $v) {
            if (is_string($v) && self::isSecretField((string) $k) && $v !== '') {
                $config[$k] = self::encrypt($v);
            }
        }
        return $config;
    }

    /** Apply decrypt() to every secret field of a config array. */
    public static function openConfig(array $config): array
    {
        foreach ($config as $k => $v) {
            if (is_string($v) && self::isSecretField((string) $k) && $v !== '') {
                $config[$k] = self::decrypt($v);
            }
        }
        return $config;
    }

    /** Load (creating on first use) the 256-bit key from config/credential_key.php. */
    private static function loadKey(): ?string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        if (self::$unavailable) {
            return null;
        }
        $file = dirname(__DIR__, 2) . '/config/credential_key.php';
        if (is_file($file)) {
            $hex = @include $file;
            if (is_string($hex) && preg_match('/^[0-9a-f]{64}$/', $hex)) {
                self::$key = hex2bin($hex);
                return self::$key;
            }
            self::$unavailable = true;
            return null;
        }
        // First use: generate and persist. If the FS is read-only, degrade.
        try {
            $hex = bin2hex(random_bytes(32));
            if (@file_put_contents($file, "<?php return '{$hex}';\n") === false) {
                self::$unavailable = true;
                return null;
            }
            @chmod($file, 0640);
            self::$key = hex2bin($hex);
            return self::$key;
        } catch (\Throwable) {
            self::$unavailable = true;
            return null;
        }
    }
}
