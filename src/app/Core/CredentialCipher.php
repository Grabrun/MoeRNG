<?php
declare(strict_types=1);

namespace App\Core;

/**
 * v1.3.0-beta.2 安全加固 (CVE-2026-MR-013, CWE-312): at-rest encryption for
 * stored credentials (storage AccessKey/SecretKey, etc.).
 *
 * Threat model: a database leak (SQL dump, misconfigured backup) must NOT
 * hand over usable cloud credentials. Secrets are sealed with AES-256-GCM
 * under a key that lives OUTSIDE the database, in the standard config/
 * directory as config/credentials.php (same layout and trust model as
 * config/app.php / config/database.php — plain PHP, so a direct web request
 * executes the file instead of revealing its contents). The key is read via
 * Config::get('credentials.key') like every other base setting.
 *
 * Compatibility: values without the "enc:v1:" prefix are returned as-is, so
 * pre-existing plaintext configs keep working and get re-sealed transparently
 * the next time the profile is saved. A site that already generated the
 * earlier standalone config/credential_key.php is migrated into the unified
 * config file on first use.
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

    /**
     * Load (creating on first use) the 256-bit key through the standard config
     * system: Config::get('credentials.key'), backed by config/credentials.php.
     * A site that generated the earlier standalone credential_key.php is
     * migrated transparently; a read-only config/ degrades to plaintext.
     */
    private static function loadKey(): ?string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        if (self::$unavailable) {
            return null;
        }
        $configPath = dirname(__DIR__, 2) . '/config';

        // 1) Already in the unified config (normal path after first generation).
        $existing = Config::get('credentials.key');
        if (is_string($existing) && preg_match('/^[0-9a-f]{64}$/', $existing)) {
            self::$key = hex2bin($existing);
            return self::$key;
        }

        // 2) Migration: a previous release stored the key standalone. Pull it
        //    into the unified config so credential_key.php can be retired.
        $legacyFile = $configPath . '/credential_key.php';
        if (is_file($legacyFile)) {
            $legacy = @include $legacyFile;
            if (is_string($legacy) && preg_match('/^[0-9a-f]{64}$/', $legacy)) {
                if (self::persistKey($legacy)) {
                    self::$key = hex2bin($legacy);
                    @unlink($legacyFile); // best-effort cleanup of the retired file
                    return self::$key;
                }
                // could not persist — keep using the legacy key in-memory
                self::$key = hex2bin($legacy);
                return self::$key;
            }
        }

        // 3) First use: generate and persist into the unified config.
        try {
            $hex = bin2hex(random_bytes(32));
            if (!self::persistKey($hex)) {
                self::$unavailable = true;
                return null;
            }
            self::$key = hex2bin($hex);
            return self::$key;
        } catch (\Throwable) {
            self::$unavailable = true;
            return null;
        }
    }

    /** Write the key into config/credentials.php and mirror it into Config memory. */
    private static function persistKey(string $hex): bool
    {
        $configPath = dirname(__DIR__, 2) . '/config';
        if (!is_dir($configPath)) {
            @mkdir($configPath, 0755, true);
        }
        $file = $configPath . '/credentials.php';
        $content = "<?php\n\n// v1.3.0-beta.2 (CVE-2026-MR-013): at-rest encryption key for stored\n// credentials (storage AccessKey/SecretKey). Part of the site's base config —\n// same trust model as database settings. Do NOT commit to git.\n\nreturn [\n    'key' => '{$hex}',\n];\n";
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($file, 0640);
        Config::set('credentials.key', $hex);
        return true;
    }
}
