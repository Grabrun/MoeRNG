<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/** 零依赖 SMTP 发信，配置取自 settings 的 mail_* 键。 */
class Mailer
{
    public static function enabled(): bool
    {
        return Setting::get('mail_enabled', '0') === '1';
    }

    /** 解析主机并校验为公网 IP 防 SSRF（allow_private_smtp 可放行私网，元数据地址始终拒绝）；返回的 IP 必须直接连，勿再用 hostname。 */
    private static function assertSafeHost(string $host): string
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : (gethostbyname($host) !== $host ? gethostbyname($host) : '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException('SMTP 主机无法解析为有效地址');
        }
        if ($ip === '169.254.169.254') {
            throw new \RuntimeException('SMTP 主机不允许指向云元数据地址');
        }
        $private = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($private === false) {
            if ((bool) Config::get('app.allow_private_smtp', false)) {
                return $ip;
            }
            throw new \RuntimeException(
                'SMTP 主机不允许指向内网或保留地址。如确需使用内网邮件服务器，'
                . '请在 config/app.php 中设置 \'allow_private_smtp\' => true。'
            );
        }
        return $ip;
    }

    /** 发送一封 HTML 邮件；成功返回 ''，失败返回错误信息。 */
    public static function send(string $to, string $subject, string $html): string
    {
        $host = trim(Setting::get('mail_host', ''));
        if ($host === '') {
            return 'SMTP 服务器未配置（mail_host）';
        }
        try {
            $safeIp = self::assertSafeHost($host);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $port = (int) (Setting::get('mail_port', '465') ?: 465);
        $enc = Setting::get('mail_encryption', 'ssl');
        $user = trim(Setting::get('mail_username', ''));
        $pass = Setting::get('mail_password', '');
        $from = trim(Setting::get('mail_from', $user !== '' ? $user : 'no-reply@localhost'));
        $fromName = trim(Setting::get('mail_from_name', 'MoeRNG'));

        // 连已校验的 IP 而非 hostname：hostname 会再解析一次，攻击者可借 DNS 重绑定（TOCTOU）指向内网。
        // peer_name 用原始主机名：连的是 IP，证书校验仍须对域名，否则 ssl:// 一律校验失败。
        $context = stream_context_create([
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true],
        ]);
        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $safeIp;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            return "无法连接 SMTP 服务器：{$errstr} ({$errno})";
        }
        stream_set_timeout($fp, 15);

        $log = [];
        $cmd = function (string $c) use ($fp, &$log): bool {
            fwrite($fp, $c . "\r\n");
            $resp = fgets($fp, 512);
            $log[] = trim($c) . ' -> ' . trim((string) $resp);
            return str_starts_with((string) $resp, '2') || str_starts_with((string) $resp, '3');
        };

        if (!$cmd('EHLO moerng')) {
            fclose($fp);
            return 'EHLO 失败：' . end($log);
        }
        // TLS = 显式 STARTTLS 升级（587 端口常规做法）；ssl = 连接即 TLS（465）。
        if ($enc === 'tls') {
            if (!$cmd('STARTTLS')) {
                fclose($fp);
                return 'STARTTLS 被拒绝（服务器可能不支持，或应改用 SSL/465）：' . end($log);
            }
            if (@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                fclose($fp);
                return 'STARTTLS 握手失败：证书校验或协议协商未通过';
            }
            if (!$cmd('EHLO moerng')) {
                fclose($fp);
                return 'STARTTLS 后 EHLO 失败：' . end($log);
            }
        }
        if ($user !== '') {
            if (!$cmd('AUTH LOGIN')) {
                fclose($fp);
                return 'AUTH LOGIN 失败：' . end($log);
            }
            if (!$cmd(base64_encode($user))) {
                fclose($fp);
                return 'AUTH 用户名失败：' . end($log);
            }
            if (!$cmd(base64_encode($pass))) {
                fclose($fp);
                return 'AUTH 密码失败：' . end($log);
            }
        }
        if (!$cmd("MAIL FROM:<{$from}>")) {
            fclose($fp);
            return 'MAIL FROM 失败：' . end($log);
        }
        if (!$cmd("RCPT TO:<{$to}>")) {
            fclose($fp);
            return 'RCPT TO 失败（收件人可能被拒绝）: ' . end($log);
        }
        if (!$cmd('DATA')) {
            fclose($fp);
            return 'DATA 失败：' . end($log);
        }

        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n"
            . "To: <{$to}>\r\n"
            . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n";
        $body = chunk_split(base64_encode($html), 76, "\r\n");
        fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
        $resp = fgets($fp, 512);
        if (!str_starts_with((string) $resp, '2')) {
            fclose($fp);
            return '邮件内容发送失败：' . trim((string) $resp);
        }
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return '';
    }

    /** 向 settings.mail_test_to 发测试邮件，返回 [ok, message]。 */
    public static function test(): array
    {
        $to = trim(Setting::get('mail_test_to', ''));
        if ($to === '') {
            return [false, '请先在「邮件与通知」填写「测试收件邮箱」再发送测试。'];
        }
        $err = self::send($to, 'MoeRNG 测试邮件', '<p>这是一封来自 MoeRNG 的测试邮件。</p><p>如果你的 SMTP 配置正确，你能看到这封邮件。</p>');
        return $err === '' ? [true, '测试邮件已发送至 ' . $to] : [false, $err];
    }
}
