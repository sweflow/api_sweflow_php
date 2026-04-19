<?php

namespace Src\Kernel\Contracts;

interface EmailSenderInterface
{
    /**
     * @param list<array{email: string, name?: string}>|string $recipients
     */
    public function sendCustom(array|string $recipients, string $subject, string $htmlBody, ?string $logoUrl = null): void;

    public function sendConfirmation(string $toEmail, string $toName, string $confirmLink, ?string $logoUrl = null): void;

    public function sendPasswordReset(string $toEmail, string $toName, string $resetLink, ?string $logoUrl = null): void;
}
