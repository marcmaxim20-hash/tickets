<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Ticket;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Twig\Environment;

class TicketConfirmationMailer
{
    public const STATUS_SENT = 'sent';
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly MailerInterface $defaultMailer,
        private readonly Environment $twig,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly string $smtpHost,
        private readonly string $smtpPort,
        private readonly string $smtpUser,
        private readonly string $smtpPassword,
        private readonly string $smtpFrom,
        private readonly string $mailerDsn,
        private readonly string $showEntryCode,
    ) {
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function sendTicketConfirmation(Ticket $ticket): array
    {
        $recipient = trim($ticket->getContact());
        $mailer = $this->resolveMailer();

        if ($mailer === null) {
            return ['status' => self::STATUS_NOT_CONFIGURED];
        }

        $fromEmail = $this->resolveFromAddress();
        $entryCode = trim($this->showEntryCode) !== '' ? trim($this->showEntryCode) : '1212';

        $plainText = sprintf(
            "You bought the ticket for the show! The code of entry is %s.\n\n"
            . "Ticket Details:\n"
            . "- Ticket ID: #%d\n"
            . "- Title: %s\n"
            . "- Description: %s\n",
            $entryCode,
            (int) $ticket->getId(),
            $ticket->getTitle(),
            $ticket->getDescription()
        );

        $htmlBody = $this->twig->render('emails/ticket_confirmation.html.twig', [
            'ticket' => $ticket,
            'entry_code' => $entryCode,
        ]);

        $email = (new Email())
            ->from(new Address($fromEmail, 'SwiftDesk Tickets'))
            ->to($recipient)
            ->subject(sprintf('Your Show Ticket (#%d) — Code of Entry: %s', (int) $ticket->getId(), $entryCode))
            ->text($plainText)
            ->html($htmlBody);

        try {
            $mailer->send($email);

            return ['status' => self::STATUS_SENT];
        } catch (TransportExceptionInterface|\Throwable $exception) {
            return [
                'status' => self::STATUS_FAILED,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function sendFestivalTicketConfirmation(Ticket $ticket, string $doorUrl, string $passUrl): array
    {
        $recipient = trim($ticket->getContact());
        $mailer = $this->resolveMailer();

        if ($mailer === null) {
            return ['status' => self::STATUS_NOT_CONFIGURED];
        }

        $fromEmail = $this->resolveFromAddress();
        $expiresAtFormatted = $ticket->getQrExpiresAt()?->format('M j, Y \a\t H:i:s') ?? '24 hours from purchase';

        $qrPngBytes = $this->qrCodeGenerator->generatePng($doorUrl, 8, 3);
        $qrPart = (new DataPart($qrPngBytes, 'festival-door-qr.png', 'image/png'))->asInline();
        $qrCid = $qrPart->getContentId();

        $plainText = sprintf(
            "You bought the ticket for the festival! Scan the attached QR code to open the festival door.\n"
            . "IMPORTANT: This QR code lasts only 24 hours (valid until %s).\n\n"
            . "Festival Door Scanner Link: %s\n"
            . "Digital Pass Page: %s\n\n"
            . "Ticket Details:\n"
            . "- Ticket ID: #%d\n"
            . "- Title: %s\n"
            . "- Description: %s\n",
            $expiresAtFormatted,
            $doorUrl,
            $passUrl,
            (int) $ticket->getId(),
            $ticket->getTitle(),
            $ticket->getDescription()
        );

        $htmlBody = $this->twig->render('emails/festival_ticket_confirmation.html.twig', [
            'ticket' => $ticket,
            'qr_cid' => $qrCid,
            'door_url' => $doorUrl,
            'pass_url' => $passUrl,
            'expires_at' => $expiresAtFormatted,
        ]);

        $email = (new Email())
            ->from(new Address($fromEmail, 'SwiftDesk Festival Pass'))
            ->to($recipient)
            ->subject(sprintf('Your Festival Door QR Ticket (#%d) — Valid for 24 Hours', (int) $ticket->getId()))
            ->text($plainText)
            ->html($htmlBody)
            ->addPart($qrPart)
            ->attach($qrPngBytes, sprintf('festival-ticket-%d-qr.png', (int) $ticket->getId()), 'image/png');

        try {
            $mailer->send($email);

            return ['status' => self::STATUS_SENT];
        } catch (TransportExceptionInterface|\Throwable $exception) {
            return [
                'status' => self::STATUS_FAILED,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function resolveMailer(): ?MailerInterface
    {
        $user = trim($this->smtpUser);
        $password = trim($this->smtpPassword);
        $host = trim($this->smtpHost);
        $port = (int) trim($this->smtpPort) ?: 587;

        $hasCustomSmtp = $host !== ''
            && $user !== 'your.email@gmail.com'
            && $password !== 'your-app-password'
            && ($user !== '' || !str_contains(strtolower($host), 'gmail.com'))
            && ($password !== '' || !str_contains(strtolower($host), 'gmail.com'));

        if ($hasCustomSmtp) {
            if (str_contains(strtolower($host), 'gmail.com')) {
                $password = str_replace(' ', '', $password);
            }

            if ($user !== '') {
                $dsn = sprintf(
                    'smtp://%s:%s@%s:%d',
                    rawurlencode($user),
                    rawurlencode($password),
                    $host,
                    $port
                );
            } else {
                $dsn = sprintf('smtp://%s:%d', $host, $port);
            }

            return new Mailer(Transport::fromDsn($dsn));
        }

        $dsn = trim($this->mailerDsn);
        if ($dsn !== '' && !str_starts_with($dsn, 'null://')) {
            return $this->defaultMailer;
        }

        return null;
    }

    private function resolveFromAddress(): string
    {
        $candidate = trim($this->smtpFrom);
        if ($candidate !== '' && $candidate !== 'your.email@gmail.com' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }

        $user = trim($this->smtpUser);
        if ($user !== '' && $user !== 'your.email@gmail.com' && filter_var($user, FILTER_VALIDATE_EMAIL)) {
            return $user;
        }

        return 'no-reply@swiftdesk.local';
    }
}
