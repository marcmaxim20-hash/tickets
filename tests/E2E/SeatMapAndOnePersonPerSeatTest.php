<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeatMapAndOnePersonPerSeatTest extends WebTestCase
{
    private string $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runId = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            $container = static::getContainer();
            if ($container->has(EntityManagerInterface::class)) {
                /** @var EntityManagerInterface $em */
                $em = $container->get(EntityManagerInterface::class);
                $conn = $em->getConnection();
                $conn->executeStatement('DELETE FROM tickets WHERE contact LIKE :p', ['p' => '%_' . $this->runId . '@example.com']);
                $conn->executeStatement('DELETE FROM events WHERE title LIKE :t', ['t' => '%' . $this->runId . '%']);
                $conn->executeStatement('DELETE FROM customers WHERE email LIKE :e', ['e' => '%_' . $this->runId . '@example.com']);
            }
        }

        parent::tearDown();
    }

    public function testSeatMapAtBottomAndOnePersonPerSeatAndPositionEditing(): void
    {
        $client = static::createClient();
        $orgEmail = 'phpunit_seat_org_' . $this->runId . '@example.com';
        $cust1Email = 'phpunit_seat_c1_' . $this->runId . '@example.com';
        $cust2Email = 'phpunit_seat_c2_' . $this->runId . '@example.com';

        // 1. Register Organizer & create a Festival event with custom seat map
        $crawler = $client->request('GET', '/account/register');
        $client->request('POST', '/account/register', [
            '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'role' => 'organizer',
            'organizer_password' => '1111',
            'name' => 'Festival Host',
            'email' => $orgEmail,
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        self::assertResponseRedirects('/organizer/events/new');

        $crawler = $client->followRedirect();
        $eventToken = (string) $crawler->filter('input[name="event[_token]"]')->attr('value');

        $initialCustomMap = json_encode([
            'stage_position' => 'top',
            'rows' => 4,
            'cols' => 5,
            'cells' => [
                ['r' => 0, 'c' => 0, 'label' => 'A1', 'tier' => 'backstage_pass'],
                ['r' => 0, 'c' => 1, 'label' => 'A2', 'tier' => 'backstage_pass'],
                ['r' => 1, 'c' => 3, 'label' => 'B4', 'tier' => 'vip_pass'],
                ['r' => 3, 'c' => 4, 'label' => 'D5', 'tier' => 'day_pass'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client->request('POST', '/organizer/events/new', [
            'event' => [
                'title' => 'PHPUnit Seated Fest ' . $this->runId,
                'category' => 'festival',
                'venue' => 'Sun Arena',
                'location' => 'Marina Walk 1, Valencia',
                'totalSeats' => '20',
                'seatMapEnabled' => '1',
                'seatMapLayout' => 'festival',
                'seatRows' => '4',
                'seatsPerRow' => '5',
                'seatMapConfigJson' => $initialCustomMap,
                'startDate' => '2026-12-22T18:00',
                'endDate' => '2026-12-22T23:59',
                'imageUrl' => '',
                'description' => 'Seated festival E2E test.',
                '_token' => $eventToken,
            ],
        ]);
        self::assertResponseRedirects('/account');

        $crawler = $client->followRedirect();
        $arrangeHref = (string) $crawler->filter('a:contains("Arrange Seats")')->first()->attr('href');
        self::assertMatchesRegularExpression('#/organizer/events/(\d+)/seats#', $arrangeHref);
        preg_match('#/organizer/events/(\d+)/seats#', $arrangeHref, $matches);
        $eventId = (int) $matches[1];

        // 2. Organizer rearranges seat positions (moves stage to LEFT & repaints B4 to 39$)
        $crawler = $client->request('GET', '/organizer/events/' . $eventId . '/seats');
        self::assertResponseIsSuccessful();
        $arrangeCsrf = (string) $crawler->filter('form[action="/organizer/events/' . $eventId . '/seats"] input[name="_csrf_token"]')->attr('value');

        $updatedMap = json_encode([
            'stage_position' => 'left',
            'rows' => 4,
            'cols' => 5,
            'cells' => [
                ['r' => 0, 'c' => 0, 'label' => 'A1', 'tier' => 'backstage_pass'],
                ['r' => 0, 'c' => 4, 'label' => 'A2', 'tier' => 'backstage_pass'],
                ['r' => 2, 'c' => 2, 'label' => 'B4', 'tier' => 'full_pass'],
                ['r' => 3, 'c' => 4, 'label' => 'D5', 'tier' => 'day_pass'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client->request('POST', '/organizer/events/' . $eventId . '/seats', [
            '_csrf_token' => $arrangeCsrf,
            'seat_map_layout' => 'festival',
            'seat_rows' => '4',
            'seats_per_row' => '5',
            'seat_map_config_json' => $updatedMap,
        ]);
        self::assertResponseRedirects('/events/' . $eventId);

        // 3. Seat map is at the BOTTOM of the festival page (after #tiers-heading) with stage on LEFT
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $eventHtml = (string) $client->getResponse()->getContent();
        $tiersPos = strpos($eventHtml, 'id="tiers-heading"');
        $mapPos = strpos($eventHtml, 'id="event-seat-map"');
        self::assertNotFalse($tiersPos);
        self::assertNotFalse($mapPos);
        self::assertGreaterThan($tiersPos, $mapPos, 'Seat map must appear at the bottom below ticket tiers.');
        self::assertStringContainsString('stage-pos-left', $eventHtml);
        self::assertStringContainsString('1 Seat = 1 Person Only', $eventHtml);
        self::assertStringContainsString('4 Free', $eventHtml);

        // 4. Switch to Customer 1 and buy Seat B4 directly from the seat map
        $this->registerCustomer($client, 'Customer One', $cust1Email);
        $crawler = $client->request('GET', '/events/' . $eventId);
        $buyCsrf = (string) $crawler->filter('#direct-seat-buy-form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/events/' . $eventId . '/buy-seat', [
            '_csrf_token' => $buyCsrf,
            'seat_label' => 'B4',
        ]);
        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/confirmed', $location);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $confirmHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Reserved Seat: B4 &middot; 1 Person Only', $confirmHtml);
        self::assertStringContainsString('39$', $confirmHtml);
        self::assertStringContainsString('Marina Walk 1, Valencia', $confirmHtml);

        // 5. Switch to Customer 2: Seat B4 is marked Taken and CANNOT be booked by a 2nd person
        $this->registerCustomer($client, 'Customer Two', $cust2Email);
        $crawler = $client->request('GET', '/events/' . $eventId);
        $ev2Html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('3 Free', $ev2Html);
        self::assertStringContainsString('1 Taken', $ev2Html);
        self::assertStringContainsString('Seat B4 — Already Booked (Reserved for 1 Person)', $ev2Html);

        $buyCsrf2 = (string) $crawler->filter('#direct-seat-buy-form input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/events/' . $eventId . '/buy-seat', [
            '_csrf_token' => $buyCsrf2,
            'seat_label' => 'B4',
        ]);
        self::assertResponseRedirects('/events/' . $eventId);

        $client->followRedirect();
        self::assertStringContainsString('every seat is for 1 person only', (string) $client->getResponse()->getContent());

        // 6. Checkout without choosing a seat fails with 422
        $crawler = $client->request('GET', '/tickets/new?event=' . $eventId);
        $ticketToken = (string) $crawler->filter('input[name="ticket[_token]"]')->attr('value');
        $client->request('POST', '/tickets/new', [
            'event_id' => (string) $eventId,
            'ticket' => [
                'tierKey' => 'day_pass',
                'contact' => $cust2Email,
                'seatLabel' => '',
                '_token' => $ticketToken,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    private function registerCustomer(KernelBrowser $client, string $name, string $email): void
    {
        $crawler = $client->request('GET', '/account/register');
        $csrf = (string) $crawler->filter('form[action="/account/register"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account/register', [
            '_csrf_token' => $csrf,
            'role' => 'customer',
            'name' => $name,
            'email' => $email,
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        self::assertResponseRedirects('/account');
    }
}
