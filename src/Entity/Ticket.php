<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketRepository;
use App\Service\TicketCatalog;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'tickets')]
#[ORM\Index(name: 'idx_tickets_status', columns: ['status'])]
#[ORM\Index(name: 'idx_tickets_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_tickets_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_tickets_event', columns: ['event_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tickets_qr_token', columns: ['qr_token'])]
#[ORM\UniqueConstraint(name: 'uniq_tickets_event_seat', columns: ['event_id', 'seat_label'])]
class Ticket
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';

    public const TYPE_STANDARD = 'standard';
    public const TYPE_FESTIVAL = 'festival';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $title = 'Preferred Front-Zone Pass';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Please enter your email address.')]
    #[Assert\Email(message: 'Please enter a valid email address (for example, name@gmail.com).')]
    #[Assert\Length(max: 180, maxMessage: 'Email cannot exceed {{ limit }} characters.')]
    private string $contact = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = 'Single-entry QR pass.';

    #[ORM\Column(name: 'tier_key', length: 40, options: ['default' => TicketCatalog::DEFAULT_TIER])]
    #[Assert\NotBlank]
    private string $tierKey = TicketCatalog::DEFAULT_TIER;

    #[ORM\Column(name: 'price_cents', options: ['default' => 3900])]
    private int $priceCents = 3900;

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'ticket_type', length: 32, options: ['default' => self::TYPE_FESTIVAL])]
    private string $ticketType = self::TYPE_FESTIVAL;

    #[ORM\Column(name: 'qr_token', length: 64, nullable: true)]
    private ?string $qrToken = null;

    #[ORM\Column(name: 'qr_expires_at', nullable: true)]
    private ?\DateTimeImmutable $qrExpiresAt = null;

    #[ORM\Column(name: 'used_at', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'tickets')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'tickets')]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Event $event = null;

    #[ORM\Column(name: 'seat_label', length: 16, nullable: true)]
    #[Assert\Length(max: 16)]
    private ?string $seatLabel = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @return array<string, string>
     */
    public static function getAvailableStatuses(): array
    {
        return [
            self::STATUS_OPEN => 'Open',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_RESOLVED => 'Resolved',
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getContact(): string
    {
        return $this->contact;
    }

    public function setContact(string $contact): self
    {
        $this->contact = trim($contact);

        return $this;
    }

    public function isEmailContact(): bool
    {
        return filter_var($this->contact, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = trim($description);

        return $this;
    }

    public function getTierKey(): string
    {
        return $this->tierKey;
    }

    public function setTierKey(string $tierKey): self
    {
        $this->tierKey = trim($tierKey);

        return $this;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): self
    {
        $this->priceCents = max(0, $priceCents);

        return $this;
    }

    public function getFormattedPrice(): string
    {
        $dollars = ($this->priceCents % 100 === 0)
            ? (string) intdiv($this->priceCents, 100)
            : number_format($this->priceCents / 100, 2);

        return $dollars . '$';
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return self::getAvailableStatuses()[$this->status] ?? ucfirst($this->status);
    }

    public function setStatus(string $status): self
    {
        if (!array_key_exists($status, self::getAvailableStatuses())) {
            throw new \InvalidArgumentException(sprintf('Invalid ticket status "%s".', $status));
        }

        if ($this->status !== $status) {
            $this->status = $status;
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getTicketType(): string
    {
        return $this->ticketType;
    }

    public function isFestivalTicket(): bool
    {
        return $this->qrToken !== null;
    }

    public function initializeFestivalQr(?string $uniqueToken = null, ?\DateTimeImmutable $now = null): self
    {
        $issuedAt = $now ?? new \DateTimeImmutable();
        $this->ticketType = self::TYPE_FESTIVAL;
        $this->qrToken = $uniqueToken ?? bin2hex(random_bytes(16));

        $defaultExpiry = $issuedAt->modify('+24 hours');
        $eventEnd = $this->event?->getEndDate();
        $this->qrExpiresAt = ($eventEnd !== null && $eventEnd > $defaultExpiry) ? $eventEnd : $defaultExpiry;
        $this->updatedAt = $issuedAt;

        return $this;
    }

    public function getQrToken(): ?string
    {
        return $this->qrToken;
    }

    public function getQrExpiresAt(): ?\DateTimeImmutable
    {
        return $this->qrExpiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function resetUsedStatus(): self
    {
        $this->usedAt = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function getSeatLabel(): ?string
    {
        return $this->seatLabel;
    }

    public function setSeatLabel(?string $seatLabel): self
    {
        if ($seatLabel === null) {
            $this->seatLabel = null;
        } else {
            $normalized = strtoupper(trim($seatLabel));
            $this->seatLabel = $normalized !== '' ? $normalized : null;
        }

        return $this;
    }

    public function isOwnedBy(Customer $customer): bool
    {
        if ($this->customer !== null && $this->customer->getId() !== null) {
            return $this->customer->getId() === $customer->getId();
        }

        return mb_strtolower($this->contact) === mb_strtolower($customer->getEmail());
    }

    /**
     * True only when this ticket was genuinely purchased on the site by a registered customer
     * for its linked event (excluding legacy backfilled or unowned rows).
     */
    public function isSiteSeatPurchase(): bool
    {
        if ($this->customer === null || $this->qrToken === null || $this->event === null) {
            return false;
        }

        return $this->createdAt >= $this->event->getCreatedAt()->modify('-5 minutes');
    }

    public function isQrExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->qrExpiresAt === null) {
            return false;
        }

        $checkTime = $now ?? new \DateTimeImmutable();

        return $checkTime > $this->qrExpiresAt;
    }

    public function isQrValid(?\DateTimeImmutable $now = null): bool
    {
        if ($this->qrToken === null || $this->isUsed()) {
            return false;
        }

        return !$this->isQrExpired($now);
    }

    public function getRemainingQrSeconds(?\DateTimeImmutable $now = null): int
    {
        if ($this->qrExpiresAt === null) {
            return 0;
        }

        $checkTime = $now ?? new \DateTimeImmutable();

        return max(0, $this->qrExpiresAt->getTimestamp() - $checkTime->getTimestamp());
    }

    public function expireQrNow(): self
    {
        $now = new \DateTimeImmutable();
        $this->qrExpiresAt = $now->modify('-1 minute');
        $this->updatedAt = $now;

        return $this;
    }

    public function renewQrFor24Hours(): self
    {
        $now = new \DateTimeImmutable();
        $this->qrExpiresAt = $now->modify('+24 hours');
        $this->updatedAt = $now;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
