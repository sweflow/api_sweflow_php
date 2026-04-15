<?php

namespace Src\Kernel\Support;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Src\Kernel\Contracts\EmailSenderInterface;

class MailerService implements EmailSenderInterface
{
    private function make(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = (string) ($_ENV['MAILER_HOST']       ?? 'smtp.gmail.com');
        $mail->Port       = (int)    ($_ENV['MAILER_PORT']       ?? 587);
        $mail->Username   = (string) ($_ENV['MAILER_USERNAME']   ?? '');
        $mail->Password   = (string) ($_ENV['MAILER_PASSWORD']   ?? '');
        $mail->SMTPSecure = (string) ($_ENV['MAILER_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS);
        $mail->SMTPAuth   = $mail->Username !== '' && $mail->Password !== '';
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);

        $fromEmail = (string) ($_ENV['MAILER_FROM_EMAIL'] ?? $mail->Username);
        $fromName  = (string) ($_ENV['MAILER_FROM_NAME']  ?? 'Vupi.us');
        if ($fromEmail !== '') {
            $mail->setFrom($fromEmail, $fromName);
        }

        $replyTo = (string) ($_ENV['MAILER_REPLY_TO'] ?? '');
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }

        if (($_ENV['MAILER_DEBUG'] ?? 'false') === 'true') {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            // Never output to stdout — always log to error_log
            $mail->Debugoutput = static function (string $str, int $level): void {
                $safeLevel = (int) $level;
                $safeStr   = strip_tags(trim($str));
                error_log('[MAILER][' . $safeLevel . '] ' . $safeStr);
            };
        }

        return $mail;
    }

    public function sendCustom(array|string $recipients, string $subject, string $htmlBody, ?string $logoUrl = null): void
    {
        $mail = $this->make();
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        if (is_string($recipients)) {
            $recipients = [['email' => $recipients, 'name' => $recipients]];
        }

        foreach ($recipients as $r) {
            $email = is_array($r) ? ($r['email'] ?? '') : (string) $r;
            $name  = is_array($r) ? ($r['name']  ?? $email) : $email;
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($email, $name);
            }
        }

        $mail->send();
    }

    public function sendConfirmation(string $toEmail, string $toName, string $confirmLink, ?string $logoUrl = null): void
    {
        $safeName = htmlspecialchars($toName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars($confirmLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo     = $logoUrl ? "<img src='" . htmlspecialchars($this->absoluteUrl($logoUrl), ENT_QUOTES, 'UTF-8') . "' alt='Logo' style='max-height:48px;margin-bottom:16px;display:block;'><br>" : '';
        $html     = "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'></head><body style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;'>"
                  . "{$logo}<h2 style='color:#1e293b;'>Confirme seu e-mail</h2>"
                  . "<p>Olá, {$safeName}! Clique no botão abaixo para confirmar seu e-mail:</p>"
                  . "<p><a href='{$safeLink}' style='display:inline-block;background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;'>Confirmar e-mail</a></p>"
                  . "<p style='color:#64748b;font-size:0.9em;'>Ou copie e cole este link: {$safeLink}</p>"
                  . "<p style='color:#94a3b8;font-size:0.8em;'>Se você não criou uma conta, ignore este e-mail.</p>"
                  . "</body></html>";

        $this->sendCustom($toEmail, 'Confirme seu e-mail', $html, $logoUrl);
    }

    public function sendPasswordReset(string $toEmail, string $toName, string $resetLink, ?string $logoUrl = null): void
    {
        $safeName = htmlspecialchars($toName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars($resetLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo     = $logoUrl ? "<img src='" . htmlspecialchars($this->absoluteUrl($logoUrl), ENT_QUOTES, 'UTF-8') . "' alt='Logo' style='max-height:48px;margin-bottom:16px;display:block;'><br>" : '';
        $html     = "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'></head><body style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;'>"
                  . "{$logo}<h2 style='color:#1e293b;'>Redefinição de senha</h2>"
                  . "<p>Olá, {$safeName}! Clique no botão abaixo para redefinir sua senha:</p>"
                  . "<p><a href='{$safeLink}' style='display:inline-block;background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;'>Redefinir senha</a></p>"
                  . "<p style='color:#64748b;font-size:0.9em;'>Ou copie e cole este link: {$safeLink}</p>"
                  . "<p style='color:#94a3b8;font-size:0.8em;'>Se você não solicitou a redefinição, ignore este e-mail. O link expira em breve.</p>"
                  . "</body></html>";

        $this->sendCustom($toEmail, 'Redefinição de senha', $html, $logoUrl);
    }

    /**
     * Converte URL relativa em absoluta usando APP_URL.
     * E-mails precisam de URLs absolutas — clientes de e-mail não têm domínio base.
     */
    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        $base = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        return $base . '/' . ltrim($url, '/');
    }
}
