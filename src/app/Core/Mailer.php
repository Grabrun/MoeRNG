<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Minimal SMTP mailer (v1.1.0-beta.4) — pure PHP, zero dependencies, in line
 * with the project's "no third-party runtime deps" rule.
 *
 * Reads its config from the settings store (mail_* keys). Supports AUTH LOGIN,
 * SSL/TLS/plain, and base64-encoded UTF-8 subject/from-name so Chinese text
 * survives the wire.
 */
class Mailer
{
    public static function enabled(): bool
    {
        return Setting::get('mail_enabled', '0') === '1';
    }

    /**
     * Send one HTML email. Returns '' on success, or a human-readable error.
     */
    public static function send(string $to, string $subject, string $html): string
    {
        $host = trim(Setting::get('mail_host', ''));
        if ($host === '') {
            return 'SMTP 服务器未配置（mail_host）';
        }
        $port = (int) (Setting::get('mail_port', '465') ?: 465);
        $enc = Setting::get('mail_encryption', 'ssl');
        $user = trim(Setting::get('mail_username', ''));
        $pass = Setting::get('mail_password', '');
        $from = trim(Setting::get('mail_from', $user !== '' ? $user : 'no-reply@localhost'));
        $fromName = trim(Setting::get('mail_from_name', 'MoeRNG'));

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host;
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($remote, $port, $errno, $errstr, 10);
        if (!$fp) {
            return "无法连接 SMTP 服务器: {$errstr} ({$errno})";
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
            return 'EHLO 失败: ' . end($log);
        }
        if ($user !== '') {
            if (!$cmd('AUTH LOGIN')) {
                fclose($fp);
                return 'AUTH LOGIN 失败: ' . end($log);
            }
            if (!$cmd(base64_encode($user))) {
                fclose($fp);
                return 'AUTH 用户名失败: ' . end($log);
            }
            if (!$cmd(base64_encode($pass))) {
                fclose($fp);
                return 'AUTH 密码失败: ' . end($log);
            }
        }
        if (!$cmd("MAIL FROM:<{$from}>")) {
            fclose($fp);
            return 'MAIL FROM 失败: ' . end($log);
        }
        if (!$cmd("RCPT TO:<{$to}>")) {
            fclose($fp);
            return 'RCPT TO 失败（收件人可能被拒绝）: ' . end($log);
        }
        if (!$cmd('DATA')) {
            fclose($fp);
            return 'DATA 失败: ' . end($log);
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
            return '邮件内容发送失败: ' . trim((string) $resp);
        }
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return '';
    }

    /** Send a test email to settings.mail_test_to. Returns [ok, message]. */
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
