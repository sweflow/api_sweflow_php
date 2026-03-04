<?php

namespace Src\Modules\Email\Controllers;

use Src\Http\Response\Response;
use Src\Modules\Email\Services\EmailService;

class EmailController
{
    private EmailService $service;

    public function __construct(EmailService $service)
    {
        $this->service = $service;
    }

    public function sendCustom($request): Response
    {
        $body = $request->body ?? [];
        $recipients = $body['recipients'] ?? null;
        $toLegacy = trim($body['to'] ?? '');
        $subject = trim($body['subject'] ?? '');
        $html = trim($body['html'] ?? '');
        $logo = $body['logo_url'] ?? ($_ENV['APP_LOGO_URL'] ?? null);

        if (($recipients === null || $recipients === '') && $toLegacy !== '') {
            $recipients = [$toLegacy];
        }

        if ($recipients === null || $recipients === '' || (is_array($recipients) && count($recipients) === 0)) {
            return Response::json(['error' => 'Informe pelo menos um destinatário.'], 422);
        }

        if ($subject === '' || $html === '') {
            return Response::json(['error' => 'Campos obrigatórios: subject, html'], 422);
        }

        try {
            $this->service->sendCustom($recipients, $subject, $html, $logo ?: null);
            return Response::json(['message' => 'E-mail enviado com sucesso.']);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Falha ao enviar e-mail.', 'detail' => $e->getMessage()], 500);
        }
    }
}
