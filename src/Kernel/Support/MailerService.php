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
        $mail->SMTPAuth   = $mail->Username !== '';
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);

        $fromEmail = (string) ($_ENV['MAILER_FROM_EMAIL'] ?? $mail->Username);
        $fromName  = (string) ($_ENV['MAILER_FROM_NAME']  ?? 'Sweflow');
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
                error_log('[MAILER][' . $level . '] ' . trim(strip_tags($str)));
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
        $logo  = $logoUrl ? "<img src='{$logoUrl}' alt='Logo' style='max-height:48px;margin-bottom:16px;'><br>" : '';
        $html  = "{$logo}<h2>Confirme seu e-mail</h2>"
               . "<p>Olá, {$toName}! Clique no link abaixo para confirmar seu e-mail:</p>"
               . "<p><a href='{$confirmLink}'>{$confirmLink}</a></p>";

        $this->sendCustom($toEmail, 'Confirme seu e-mail', $html, $logoUrl);
    }

    public function sendPasswordReset(string $toEmail, string $toName, string $resetLink, ?string $logoUrl = null): void
    {
        $logo  = $logoUrl ? "<img src='{$logoUrl}' alt='Logo' style='max-height:48px;margin-bottom:16px;'><br>" : '';
        $html  = "{$logo}<h2>Redefinição de senha</h2>"
               . "<p>Olá, {$toName}! Clique no link abaixo para redefinir sua senha:</p>"
               . "<p><a href='{$resetLink}'>{$resetLink}</a></p>"
               . "<p>Se não solicitou, ignore este e-mail.</p>";

        $this->sendCustom($toEmail, 'Redefinição de senha', $html, $logoUrl);
    }
}
