<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'idx_events_category', columns: ['category'])]
#[ORM\Index(name: 'idx_events_start_date', columns: ['start_date'])]
#[ORM\Index(name: 'idx_events_organizer', columns: ['organizer_id'])]
class Event
{
    public const CATEGORY_CONCERT = 'concert';
    public const CATEGORY_THEATRE = 'theatre';
    public const CATEGORY_FESTIVAL = 'festival';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Please enter the event title.')]
    #[Assert\Length(min: 3, max: 180)]
    private string $title = '';

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: [self::CATEGORY_CONCERT, self::CATEGORY_THEATRE, self::CATEGORY_FESTIVAL])]
    private string $category = self::CATEGORY_FESTIVAL;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Please enter the venue or place name.')]
    #[Assert\Length(min: 2, max: 180)]
    private string $venue = '';

    #[ORM\Column(length: 255, options: ['default' => ''])]
    #[Assert\NotBlank(message: 'Please enter the event location or street address so guests know where to go.')]
    #[Assert\Length(min: 3, max: 255)]
    private string $location = '';

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'organizer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $organizer = null;

    #[ORM\Column(name: 'total_seats')]
    #[Assert\Positive(message: 'Total seats must be at least 1.')]
    private int $totalSeats = 100;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Please enter a short description of the event.')]
    private string $description = '';

    #[ORM\Column(name: 'image_path', length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(name: 'start_date')]
    #[Assert\NotNull(message: 'Please choose a start date and time.')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date')]
    #[Assert\NotNull(message: 'Please choose an end date and time.')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, Ticket>
     */
    #[ORM\OneToMany(targetEntity: Ticket::class, mappedBy: 'event')]
    private Collection $tickets;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->startDate = $now->modify('+7 days')->setTime(19, 0);
        $this->endDate = $now->modify('+7 days')->setTime(23, 30);
        $this->tickets = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validateDateRange(ExecutionContextInterface $context): void
    {
        if ($this->startDate !== null && $this->endDate !== null && $this->endDate <= $this->startDate) {
            $context->buildViolation('End date & time must be after the start date & time.')
                ->atPath('endDate')
                ->addViolation();
        }
    }

    /**
     * @return array<string, string>
     */
    public static function getAvailableCategories(): array
    {
        return [
            self::CATEGORY_CONCERT => 'Concert',
            self::CATEGORY_THEATRE => 'Theatre',
            self::CATEGORY_FESTIVAL => 'Festival',
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getCategoryLabel(): string
    {
        return self::getAvailableCategories()[$this->category] ?? ucfirst($this->category);
    }

    public function setCategory(string $category): self
    {
        if (array_key_exists($category, self::getAvailableCategories())) {
            $this->category = $category;
        }

        return $this;
    }

    public function getVenue(): string
    {
        return $this->venue;
    }

    public function setVenue(string $venue): self
    {
        $this->venue = trim($venue);

        return $this;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): self
    {
        $this->location = trim($location);

        return $this;
    }

    public function getMapsUrl(): ?string
    {
        $queryParts = [];
        if ($this->venue !== '') {
            $queryParts[] = $this->venue;
        }
        if ($this->location !== '' && mb_strtolower($this->location) !== mb_strtolower($this->venue)) {
            $queryParts[] = $this->location;
        }

        if ($queryParts === []) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', $queryParts));
    }

    public function getOrganizer(): ?Customer
    {
        return $this->organizer;
    }

    public function setOrganizer(?Customer $organizer): self
    {
        $this->organizer = $organizer;

        return $this;
    }

    public function getTotalSeats(): int
    {
        return $this->totalSeats;
    }

    public function setTotalSeats(int $totalSeats): self
    {
        $this->totalSeats = max(1, $totalSeats);

        return $this;
    }

    public function getSoldTicketsCount(): int
    {
        $count = 0;
        foreach ($this->tickets as $ticket) {
            if ($ticket->isSiteSeatPurchase()) {
                ++$count;
            }
        }

        return $count;
    }

    public function getAvailableSeats(): int
    {
        return max(0, $this->totalSeats - $this->getSoldTicketsCount());
    }

    public function isSoldOut(): bool
    {
        return $this->getAvailableSeats() <= 0;
    }

    public function getOccupancyPercent(): int
    {
        if ($this->totalSeats <= 0) {
            return 100;
        }

        return min(100, (int) round(($this->getSoldTicketsCount() / $this->totalSeats) * 100));
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

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function getDisplayImageUrl(): string
    {
        if ($this->imagePath !== null && $this->imagePath !== '') {
            if (
                str_starts_with($this->imagePath, 'http://')
                || str_starts_with($this->imagePath, 'https://')
            ) {
                return $this->imagePath;
            }

            return '/' . ltrim($this->imagePath, '/');
        }

        return match ($this->category) {
            self::CATEGORY_CONCERT => '/images/events/concert-arena.svg',
            self::CATEGORY_THEATRE => '/images/events/theatre-grand.svg',
            default => '/images/events/festival-sunset.svg',
        };
    }

    public function setImagePath(?string $imagePath): self
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

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
