<?php

namespace Src\Modules\Email\Controllers;

use Src\Kernel\Contracts\EmailSenderInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\EmailHistory;

class EmailController
{
    private EmailHistory $history;
    private ?EmailSenderInterface $mailer;

    public function __construct(?EmailSenderInterface $mailer = null)
    {
        $this->mailer  = $mailer;
        $this->history = new EmailHistory(dirname(__DIR__, 4) . '/storage');
    }

    private function emailModuleEnabled(): bool
    {
        $stateFile = dirname(__DIR__, 4) . '/storage/modules_state.json';
        if (!is_file($stateFile)) {
            return false;
        }
        $state = json_decode((string) file_get_contents($stateFile), true) ?? [];
        $enabled = $state['Email'] ?? $state['email'] ?? null;
        return $enabled === true;
    }

    public function enviar(Request $request): Response
    {
        if (!$this->emailModuleEnabled()) {
            return Response::json(['error' => 'Módulo de e-mail não está instalado ou está desabilitado.'], 503);
        }
        if (!$this->mailer) {
            return Response::json(['error' => 'Módulo de e-mail não configurado. Preencha MAILER_HOST e MAILER_USERNAME no .env.'], 503);
        }

        $body       = $request->body ?? [];
        $recipients = $body['recipients'] ?? [];
        $subject    = trim((string) ($body['subject'] ?? ''));
        $html       = trim((string) ($body['html'] ?? ''));
        $logoUrl    = trim((string) ($body['logo_url'] ?? '')) ?: null;

        if (empty($recipients) || $subject === '' || $html === '') {
            return Response::json(['error' => 'Destinatários, assunto e conteúdo são obrigatórios.'], 422);
        }

        $entry = ['subject' => $subject, 'recipients' => $recipients, 'html' => $html, 'logo_url' => $logoUrl, 'status' => 'enviado', 'error' => null];

        try {
            $this->mailer->sendCustom($recipients, $subject, $html, $logoUrl);
        } catch (\Throwable $e) {
            $entry['status'] = 'falhou';
            $entry['error']  = $e->getMessage();
            $saved = $this->history->save($entry);
            return Response::json(['error' => $e->getMessage(), 'id' => $saved['id']], 500);
        }

        $saved = $this->history->save($entry);
        return Response::json(['message' => 'E-mail enviado com sucesso.', 'id' => $saved['id']]);
    }

    public function listarHistorico(Request $request): Response
    {
        return Response::json(['items' => $this->history->all()]);
    }

    public function detalheHistorico(Request $request, string $id): Response
    {
        $entry = $this->history->find($id);
        if (!$entry) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }
        return Response::json($entry);
    }

    public function deletarHistorico(Request $request, string $id): Response
    {
        if (!$this->history->delete($id)) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }
        return Response::json(['message' => 'Registro excluído.']);
    }

    public function reenviar(Request $request, string $id): Response
    {
        $entry = $this->history->find($id);
        if (!$entry) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }

        if (!$this->emailModuleEnabled()) {
            $this->history->save([
                'subject' => $entry['subject'], 'recipients' => $entry['recipients'],
                'html' => $entry['html'], 'logo_url' => $entry['logo_url'] ?? null,
                'status' => 'falhou', 'error' => 'Módulo de e-mail não está instalado ou está desabilitado.',
                'resent_from' => $id,
            ]);
            return Response::json(['error' => 'Módulo de e-mail não está instalado ou está desabilitado.', 'module_disabled' => true], 503);
        }

        if (!$this->mailer) {
            $this->history->save([
                'subject' => $entry['subject'], 'recipients' => $entry['recipients'],
                'html' => $entry['html'], 'logo_url' => $entry['logo_url'] ?? null,
                'status' => 'falhou', 'error' => 'Módulo de e-mail não configurado.',
                'resent_from' => $id,
            ]);
            return Response::json(['error' => 'Módulo de e-mail não configurado.', 'module_disabled' => true], 503);
        }

        try {
            $this->mailer->sendCustom($entry['recipients'], $entry['subject'], $entry['html'], $entry['logo_url'] ?? null);
            $this->history->save([
                'subject' => $entry['subject'], 'recipients' => $entry['recipients'],
                'html' => $entry['html'], 'logo_url' => $entry['logo_url'] ?? null,
                'status' => 'enviado', 'error' => null, 'resent_from' => $id,
            ]);
            return Response::json(['message' => 'E-mail reenviado com sucesso.']);
        } catch (\Throwable $e) {
            $this->history->save([
                'subject' => $entry['subject'], 'recipients' => $entry['recipients'],
                'html' => $entry['html'], 'logo_url' => $entry['logo_url'] ?? null,
                'status' => 'falhou', 'error' => $e->getMessage(), 'resent_from' => $id,
            ]);
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }
}
