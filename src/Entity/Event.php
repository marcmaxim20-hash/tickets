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
    public const CATEGORY_FESTIVAL = 'festival';
    public const CATEGORY_THEATRE = 'theatre';
    public const CATEGORY_CINEMA = 'cinema';
    public const CATEGORY_COMEDY = 'comedy';
    public const CATEGORY_SPORTS = 'sports';
    public const CATEGORY_SALOON = 'saloon';
    public const CATEGORY_BUILDING = 'building';
    public const CATEGORY_CONFERENCE = 'conference';
    public const CATEGORY_WORKSHOP = 'workshop';
    public const CATEGORY_NIGHTLIFE = 'nightlife';
    public const CATEGORY_EXHIBITION = 'exhibition';

    public const LAYOUT_THEATRE = 'theatre';
    public const LAYOUT_SALOON = 'saloon';
    public const LAYOUT_BUILDING = 'building';
    public const LAYOUT_FESTIVAL = 'festival';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Please enter the event title.')]
    #[Assert\Length(min: 3, max: 180)]
    private string $title = '';

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: [
        self::CATEGORY_CONCERT,
        self::CATEGORY_FESTIVAL,
        self::CATEGORY_THEATRE,
        self::CATEGORY_CINEMA,
        self::CATEGORY_COMEDY,
        self::CATEGORY_SPORTS,
        self::CATEGORY_SALOON,
        self::CATEGORY_BUILDING,
        self::CATEGORY_CONFERENCE,
        self::CATEGORY_WORKSHOP,
        self::CATEGORY_NIGHTLIFE,
        self::CATEGORY_EXHIBITION,
    ])]
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

    #[ORM\Column(name: 'seat_map_enabled', options: ['default' => true])]
    private bool $seatMapEnabled = true;

    #[ORM\Column(name: 'seat_map_layout', length: 32, options: ['default' => self::LAYOUT_THEATRE])]
    #[Assert\Choice(choices: [
        self::LAYOUT_THEATRE,
        self::LAYOUT_SALOON,
        self::LAYOUT_BUILDING,
        self::LAYOUT_FESTIVAL,
    ])]
    private string $seatMapLayout = self::LAYOUT_THEATRE;

    #[ORM\Column(name: 'seat_rows', options: ['default' => 8])]
    #[Assert\Range(min: 1, max: 26, notInRangeMessage: 'Seat map rows must be between {{ min }} and {{ max }} (A–Z).')]
    private int $seatRows = 8;

    #[ORM\Column(name: 'seats_per_row', options: ['default' => 10])]
    #[Assert\Range(min: 2, max: 20, notInRangeMessage: 'Seats per row must be between {{ min }} and {{ max }}.')]
    private int $seatsPerRow = 10;

    /**
     * Optional custom seat map layout (stage_position + exact cell positions/labels/tiers).
     *
     * @var ?array{
     *     stage_position?: string,
     *     rows?: int,
     *     cols?: int,
     *     cells?: list<array{r: int, c: int, label: string, tier: string}>
     * }
     */
    #[ORM\Column(name: 'seat_map_config', type: Types::JSON, nullable: true)]
    private ?array $seatMapConfig = null;

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
            self::CATEGORY_FESTIVAL => 'Festival',
            self::CATEGORY_THEATRE => 'Theatre',
            self::CATEGORY_CINEMA => 'Cinema / Movie',
            self::CATEGORY_COMEDY => 'Comedy / Stand-Up',
            self::CATEGORY_SPORTS => 'Sports & Stadium',
            self::CATEGORY_SALOON => 'Saloon / Hall',
            self::CATEGORY_BUILDING => 'Indoor Building',
            self::CATEGORY_CONFERENCE => 'Conference & Talk',
            self::CATEGORY_WORKSHOP => 'Workshop & Class',
            self::CATEGORY_NIGHTLIFE => 'Club & Nightlife',
            self::CATEGORY_EXHIBITION => 'Art & Exhibition',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getAvailableSeatMapLayouts(): array
    {
        return [
            self::LAYOUT_THEATRE => 'Theatre / Auditorium (2 Aisles)',
            self::LAYOUT_SALOON => 'Saloon / Hall (Airplane Center Aisle)',
            self::LAYOUT_BUILDING => 'Indoor Building / Arena Hall',
            self::LAYOUT_FESTIVAL => 'Festival / Seated Arena (Wide Aisles)',
        ];
    }

    public function isIndoorCategory(): bool
    {
        return in_array($this->category, [
            self::CATEGORY_THEATRE,
            self::CATEGORY_CINEMA,
            self::CATEGORY_COMEDY,
            self::CATEGORY_SALOON,
            self::CATEGORY_BUILDING,
            self::CATEGORY_CONFERENCE,
            self::CATEGORY_WORKSHOP,
            self::CATEGORY_EXHIBITION,
        ], true);
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

    public function getFullAddressQuery(): ?string
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

        return implode(', ', $queryParts);
    }

    public function getMapsUrl(): ?string
    {
        $query = $this->getFullAddressQuery();
        if ($query === null) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }

    public function getDirectionsUrl(): ?string
    {
        $query = $this->getFullAddressQuery();
        if ($query === null) {
            return null;
        }

        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($query);
    }

    public function getMapsEmbedUrl(): ?string
    {
        $query = $this->getFullAddressQuery();
        if ($query === null) {
            return null;
        }

        return 'https://maps.google.com/maps?q=' . rawurlencode($query) . '&t=&z=15&ie=UTF8&iwloc=&output=embed';
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

    public function isSeatMapEnabled(): bool
    {
        return $this->seatMapEnabled;
    }

    public function setSeatMapEnabled(bool $seatMapEnabled): self
    {
        $this->seatMapEnabled = $seatMapEnabled;

        return $this;
    }

    public function getSeatMapLayout(): string
    {
        return $this->seatMapLayout;
    }

    public function getSeatMapLayoutLabel(): string
    {
        return self::getAvailableSeatMapLayouts()[$this->seatMapLayout] ?? 'Theatre / Auditorium';
    }

    public function setSeatMapLayout(string $seatMapLayout): self
    {
        if (array_key_exists($seatMapLayout, self::getAvailableSeatMapLayouts())) {
            $this->seatMapLayout = $seatMapLayout;
        }

        return $this;
    }

    public function getSeatRows(): int
    {
        return $this->seatRows;
    }

    public function setSeatRows(int $seatRows): self
    {
        $this->seatRows = max(1, min(26, $seatRows));

        return $this;
    }

    public function getSeatsPerRow(): int
    {
        return $this->seatsPerRow;
    }

    public function setSeatsPerRow(int $seatsPerRow): self
    {
        $this->seatsPerRow = max(2, min(20, $seatsPerRow));

        return $this;
    }

    /**
     * @return ?array{
     *     stage_position?: string,
     *     rows?: int,
     *     cols?: int,
     *     cells?: list<array{r: int, c: int, label: string, tier: string}>
     * }
     */
    public function getSeatMapConfig(): ?array
    {
        return $this->seatMapConfig;
    }

    /**
     * @param ?array{
     *     stage_position?: string,
     *     rows?: int,
     *     cols?: int,
     *     cells?: list<array{r: int, c: int, label: string, tier: string}>
     * } $seatMapConfig
     */
    public function setSeatMapConfig(?array $seatMapConfig): self
    {
        $this->seatMapConfig = $seatMapConfig;

        return $this;
    }

    public function syncTotalSeatsFromSeatMap(): self
    {
        if ($this->seatMapEnabled) {
            if (
                is_array($this->seatMapConfig)
                && isset($this->seatMapConfig['cells'])
                && is_array($this->seatMapConfig['cells'])
                && count($this->seatMapConfig['cells']) > 0
            ) {
                $this->totalSeats = count($this->seatMapConfig['cells']);
            } else {
                $this->totalSeats = $this->seatRows * $this->seatsPerRow;
            }
        }

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
            self::CATEGORY_CINEMA => '/images/events/cinema-screen.svg',
            self::CATEGORY_COMEDY => '/images/events/comedy-club.svg',
            self::CATEGORY_SPORTS => '/images/events/sports-stadium.svg',
            self::CATEGORY_SALOON => '/images/events/saloon-hall.svg',
            self::CATEGORY_BUILDING => '/images/events/building-venue.svg',
            self::CATEGORY_CONFERENCE => '/images/events/conference-summit.svg',
            self::CATEGORY_WORKSHOP => '/images/events/workshop-studio.svg',
            self::CATEGORY_NIGHTLIFE => '/images/events/nightlife-club.svg',
            self::CATEGORY_EXHIBITION => '/images/events/art-exhibition.svg',
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
