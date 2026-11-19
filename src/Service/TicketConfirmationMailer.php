<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
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
    public function sendFestivalTicketConfirmation(Ticket $ticket, string $doorUrl, string $passUrl): array
    {
        $recipient = trim($ticket->getContact());
        $mailer = $this->resolveMailer();

        if ($mailer === null) {
            return ['status' => self::STATUS_NOT_CONFIGURED];
        }

        $fromEmail = $this->resolveFromAddress();
        $expiresAtFormatted = $ticket->getQrExpiresAt()?->format('M j, Y \a\t H:i') ?? 'Event End';
        $eventName = $ticket->getEvent()?->getTitle() ?? $ticket->getTitle();
        $eventVenue = $ticket->getEvent()?->getVenue() ?? '';
        $eventLocation = $ticket->getEvent()?->getLocation() ?? '';

        $qrPngBytes = $this->qrCodeGenerator->generatePng($doorUrl, 8, 3);
        $qrPart = (new DataPart($qrPngBytes, 'festival-door-qr.png', 'image/png'))->asInline();
        $qrCid = $qrPart->getContentId();

        $whereLine = '';
        if ($eventVenue !== '' || $eventLocation !== '') {
            $whereLine = sprintf(
                "Where to go: %s%s\n",
                $eventVenue,
                ($eventLocation !== '' && $eventLocation !== $eventVenue) ? ' — ' . $eventLocation : ''
            );
        }

        $plainText = sprintf(
            "You bought the ticket for %s!\n"
            . "Event: %s\n"
            . "%s"
            . "Tier: %s (%s)\n"
            . "Valid until: %s\n\n"
            . "View your ticket in your account: %s\n",
            $ticket->getFormattedPrice(),
            $eventName,
            $whereLine,
            $ticket->getTitle(),
            $ticket->getFormattedPrice(),
            $expiresAtFormatted,
            $passUrl
        );

        $htmlBody = $this->twig->render('emails/festival_ticket_confirmation.html.twig', [
            'ticket' => $ticket,
            'qr_cid' => $qrCid,
            'door_url' => $doorUrl,
            'pass_url' => $passUrl,
            'expires_at' => $expiresAtFormatted,
        ]);

        $email = (new Email())
            ->from(new Address($fromEmail, 'SwiftDesk Tickets'))
            ->to($recipient)
            ->subject(sprintf('Your Ticket (#%d) — %s (%s)', (int) $ticket->getId(), $eventName, $ticket->getFormattedPrice()))
            ->text($plainText)
            ->html($htmlBody)
            ->addPart($qrPart)
            ->attach($qrPngBytes, sprintf('ticket-%d-qr.png', (int) $ticket->getId()), 'image/png');

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
    public function sendPasswordResetEmail(Customer $customer, string $resetUrl): array
    {
        $mailer = $this->resolveMailer();
        if ($mailer === null) {
            return ['status' => self::STATUS_NOT_CONFIGURED];
        }

        $fromEmail = $this->resolveFromAddress();
        $plainText = sprintf(
            "Hi %s,\n\nClick the link below to reset your SwiftDesk account password (valid for 1 hour):\n%s\n\nIf you did not request this, you can ignore this email.\n",
            $customer->getName(),
            $resetUrl
        );

        $htmlBody = $this->twig->render('emails/password_reset.html.twig', [
            'customer' => $customer,
            'reset_url' => $resetUrl,
        ]);

        $email = (new Email())
            ->from(new Address($fromEmail, 'SwiftDesk Accounts'))
            ->to($customer->getEmail())
            ->subject('Recover Your SwiftDesk Password')
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
