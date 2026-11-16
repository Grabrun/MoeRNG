<?php
declare(strict_types=1);

namespace App\Core;

/** 存储凭据用 AES-256-GCM 密封，密钥存数据库外的 config/credentials.php；无 "enc:v1:" 前缀的值按明文原样返回。 */
final class CredentialCipher
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private static ?string $key = null;
    /** 密钥文件不可读写；sealConfig() 据此失败关闭。 */
    private static bool $unavailable = false;

    public static function isSecretField(string $key): bool
    {
        $k = strtolower($key);
        return $k === 'secret'
            || $k === 'password'
            || $k === 'token'
            || $k === 'key'
            || $k === 'token_key'
            || str_ends_with($k, 'secret')
            || str_ends_with($k, '_secret')
            || str_ends_with($k, 'secret_key');
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }
        $key = self::loadKey();
        if ($key === null) {
            // 密钥不可用时原样返回；真实密钥的明文落库已由 sealConfig() 提前拦截。
            return $plain;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $key = self::loadKey();
        if ($key === null) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) { // 12 字节 nonce + 16 字节 tag + 至少 1 字节密文
            return '';
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? '' : $plain;
    }

    /** 密封配置中的密钥字段；确有明文密钥而密钥不可用时抛异常，拒绝明文落库（失败关闭）。 */
    public static function sealConfig(array $config): array
    {
        $needsSeal = false;
        foreach ($config as $k => $v) {
            if (is_string($v) && $v !== '' && self::isSecretField((string) $k)
                && !str_starts_with($v, self::PREFIX)) {
                $needsSeal = true;
                break;
            }
        }
        if ($needsSeal && self::loadKey() === null) {
            throw new CredentialCipherKeyUnavailable(
                '凭据加密密钥不可用（config/ 目录不可写或 key 文件缺失），'
                . '已拒绝以明文保存存储凭据。请恢复 config/credentials.php 的可读权限后重试。'
            );
        }

        foreach ($config as $k => $v) {
            if (is_string($v) && self::isSecretField((string) $k) && $v !== '') {
                $config[$k] = self::encrypt($v);
            }
        }
        return $config;
    }

    public static function openConfig(array $config): array
    {
        foreach ($config as $k => $v) {
            if (is_string($v) && self::isSecretField((string) $k) && $v !== '') {
                $config[$k] = self::decrypt($v);
            }
        }
        return $config;
    }

    /** 取密钥：优先 Config 的 credentials.key，其次迁移旧 credential_key.php，否则首次生成；不可用返回 null。 */
    private static function loadKey(): ?string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        if (self::$unavailable) {
            return null;
        }
        $configPath = dirname(__DIR__, 2) . '/config';

        $existing = Config::get('credentials.key');
        if (is_string($existing) && preg_match('/^[0-9a-f]{64}$/', $existing)) {
            self::$key = hex2bin($existing);
            return self::$key;
        }

        $legacyFile = $configPath . '/credential_key.php';
        if (is_file($legacyFile)) {
            $legacy = @include $legacyFile;
            if (is_string($legacy) && preg_match('/^[0-9a-f]{64}$/', $legacy)) {
                if (self::persistKey($legacy)) {
                    self::$key = hex2bin($legacy);
                    @unlink($legacyFile); // 旧文件迁移后尽力清理
                    return self::$key;
                }
                self::$key = hex2bin($legacy);
                return self::$key;
            }
        }

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

    private static function persistKey(string $hex): bool
    {
        $configPath = dirname(__DIR__, 2) . '/config';
        if (!is_dir($configPath)) {
            @mkdir($configPath, 0755, true);
        }
        $file = $configPath . '/credentials.php';
        $content = "<?php\n\n// 存储凭据的静态加密密钥，与数据库配置同一信任级别，勿提交到 git\n\nreturn [\n    'key' => '{$hex}',\n];\n";
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($file, 0640);
        Config::set('credentials.key', $hex);
        return true;
    }
}

/** sealConfig() 在需要密封但密钥不可用时抛出；调用方必须视为硬失败、不得落库。 */
final class CredentialCipherKeyUnavailable extends \RuntimeException
{
}
