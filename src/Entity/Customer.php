<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
#[ORM\UniqueConstraint(name: 'uniq_customers_email', columns: ['email'])]
#[ORM\Index(name: 'idx_customers_reset_token', columns: ['reset_token_hash'])]
#[ORM\Index(name: 'idx_customers_role', columns: ['role'])]
class Customer
{
    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_ORGANIZER = 'organizer';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 20, options: ['default' => self::ROLE_CUSTOMER])]
    private string $role = self::ROLE_CUSTOMER;

    #[ORM\Column(name: 'reset_token_hash', length: 64, nullable: true)]
    private ?string $resetTokenHash = null;

    #[ORM\Column(name: 'reset_expires_at', nullable: true)]
    private ?\DateTimeImmutable $resetExpiresAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, Ticket>
     */
    #[ORM\OneToMany(targetEntity: Ticket::class, mappedBy: 'customer')]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $tickets;

    public function __construct(string $name, string $email, string $plainPassword, string $role = self::ROLE_CUSTOMER)
    {
        $this->name = trim($name);
        $this->email = mb_strtolower(trim($email));
        $this->passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT);
        $this->setRole($role);
        $this->createdAt = new \DateTimeImmutable();
        $this->tickets = new ArrayCollection();
    }

    /**
     * @return array<string, string>
     */
    public static function getAvailableRoles(): array
    {
        return [
            self::ROLE_CUSTOMER => 'Customer',
            self::ROLE_ORGANIZER => 'Organizer',
        ];
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $normalized = mb_strtolower(trim($role));
        $this->role = array_key_exists($normalized, self::getAvailableRoles())
            ? $normalized
            : self::ROLE_CUSTOMER;

        return $this;
    }

    public function isOrganizer(): bool
    {
        return $this->role === self::ROLE_ORGANIZER;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function getRoleLabel(): string
    {
        return self::getAvailableRoles()[$this->role] ?? 'Customer';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }

    public function issuePasswordResetToken(?\DateTimeImmutable $now = null): string
    {
        $issuedAt = $now ?? new \DateTimeImmutable();
        $rawToken = bin2hex(random_bytes(24));
        $this->resetTokenHash = hash('sha256', $rawToken);
        $this->resetExpiresAt = $issuedAt->modify('+1 hour');

        return $rawToken;
    }

    public function isResetTokenValid(?\DateTimeImmutable $now = null): bool
    {
        if ($this->resetTokenHash === null || $this->resetExpiresAt === null) {
            return false;
        }

        $checkTime = $now ?? new \DateTimeImmutable();

        return $checkTime <= $this->resetExpiresAt;
    }

    public function resetPassword(string $newPlainPassword): self
    {
        $this->passwordHash = password_hash($newPlainPassword, PASSWORD_BCRYPT);
        $this->resetTokenHash = null;
        $this->resetExpiresAt = null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function getTickets(): Collection
    {
        return $this->tickets;
    }
}
