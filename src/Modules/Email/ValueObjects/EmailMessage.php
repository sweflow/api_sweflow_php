<?php

namespace Src\Modules\Email\ValueObjects;

class EmailMessage
{
    /** @var array<int,array{email:string,name:string}> */
    private array $recipients;
    private string $subject;
    private string $body;
    private ?string $logoUrl;

    /**
     * @param array<int,array{email:string,name:string}> $recipients
     */
    public function __construct(array $recipients, string $subject, string $body, ?string $logoUrl = null)
    {
        if (count($recipients) === 0) {
            throw new \InvalidArgumentException('Destinatários não informados.');
        }
        $this->recipients = array_values($recipients);
        $this->subject = $subject;
        $this->body = $body;
        $this->logoUrl = $logoUrl;
    }

    /**
     * @return array<int,array{email:string,name:string}>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }
}
