<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoleAccessAndLocationTest extends WebTestCase
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

    public function testOrganizerRequiresCode1111AndCanOnlyCreateEventsWithLocation(): void
    {
        $client = static::createClient();
        $orgEmail = 'phpunit_org_' . $this->runId . '@example.com';

        // 1. Register page displays Customer & Organizer options
        $crawler = $client->request('GET', '/account/register');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Register Customer Account');

        $csrf = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        // 2. Wrong Organizer password (9999) is rejected with 422
        $crawler = $client->request('POST', '/account/register', [
            '_csrf_token' => $csrf,
            'role' => 'organizer',
            'organizer_password' => '9999',
            'name' => 'Test Organizer',
            'email' => $orgEmail,
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Invalid Organizer password', (string) $client->getResponse()->getContent());

        // 3. Correct Organizer password (1111) succeeds and redirects to /organizer/events/new
        $csrf = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account/register', [
            '_csrf_token' => $csrf,
            'role' => 'organizer',
            'organizer_password' => '1111',
            'name' => 'Test Organizer',
            'email' => $orgEmail,
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        self::assertResponseRedirects('/organizer/events/new');

        // 4. Organizer creates a new event with a Location
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $eventTitle = 'PHPUnit Gala ' . $this->runId;
        $eventLocation = 'Carrer de la Pau 42, 46003 Valencia, Spain';
        $eventToken = (string) $crawler->filter('input[name="event[_token]"]')->attr('value');

        $client->request('POST', '/organizer/events/new', [
            'event' => [
                'title' => $eventTitle,
                'category' => 'theatre',
                'venue' => 'Palau Theatre',
                'location' => $eventLocation,
                'totalSeats' => '40',
                'seatMapEnabled' => '1',
                'seatMapLayout' => 'theatre',
                'seatRows' => '4',
                'seatsPerRow' => '10',
                'startDate' => '2026-12-20T19:00',
                'endDate' => '2026-12-20T22:30',
                'imageUrl' => '',
                'description' => 'Automated PHPUnit E2E event.',
                '_token' => $eventToken,
            ],
        ]);
        self::assertResponseRedirects('/account');

        // 5. Organizer Hub displays created event + Location + Google Maps link
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $hubHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($eventTitle, $hubHtml);
        self::assertStringContainsString($eventLocation, $hubHtml);
        self::assertStringContainsString('google.com/maps/search', $hubHtml);

        // 6. Organizer CANNOT buy tickets or access Admin panel
        $client->request('GET', '/tickets/new');
        self::assertResponseRedirects('/account');

        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function testCustomerCannotCreateEventsAsOrganizer(): void
    {
        $client = static::createClient();
        $custEmail = 'phpunit_cust_' . $this->runId . '@example.com';

        $this->registerCustomer($client, 'Regular Customer', $custEmail);

        // Customer is blocked (403) from /organizer/events/new
        $client->request('GET', '/organizer/events/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testHomePageDisplaysAllExpandedEventCategories(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        foreach ([
            'Concert',
            'Festival',
            'Theatre',
            'Cinema / Movie',
            'Comedy / Stand-Up',
            'Sports &amp; Stadium',
            'Saloon / Hall',
            'Indoor Building',
            'Conference &amp; Talk',
            'Workshop &amp; Class',
            'Club &amp; Nightlife',
            'Art &amp; Exhibition',
        ] as $label) {
            self::assertStringContainsString($label, $html);
        }

        // Filtering by one of the new categories returns 200 cleanly
        $client->request('GET', '/?category=cinema');
        self::assertResponseIsSuccessful();

        // Searching by location works both for matching cities and non-matching locations
        $client->request('GET', '/?location=Valencia');
        self::assertResponseIsSuccessful();
        $valenciaHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="event-location-search"', $valenciaHtml);
        self::assertStringContainsString('Valencia', $valenciaHtml);

        $client->request('GET', '/?location=NoSuchCityXYZ999');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No matching events found', (string) $client->getResponse()->getContent());

        // Event page displays embedded location map, address, and directions link
        $client->request('GET', '/events/1');
        self::assertResponseIsSuccessful();
        $eventHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="event-location-map"', $eventHtml);
        self::assertStringContainsString('output=embed', $eventHtml);
        self::assertStringContainsString('Get Directions', $eventHtml);
    }

    public function testAdminCanDeleteAnyEventAndOrganizerCanOnlyDeleteOwnEvents(): void
    {
        $client = static::createClient();
        $org1Email = 'phpunit_del_org1_' . $this->runId . '@example.com';
        $org2Email = 'phpunit_del_org2_' . $this->runId . '@example.com';

        // 1. Organizer 1 registers & creates Event 1
        $this->registerOrganizer($client, 'Organizer One', $org1Email);
        $event1Id = $this->createOrganizerEvent($client, 'Org1 Event ' . $this->runId);

        // 2. Organizer 2 registers & creates Event 2
        $this->registerOrganizer($client, 'Organizer Two', $org2Email);
        $event2Id = $this->createOrganizerEvent($client, 'Org2 Event ' . $this->runId);

        // 3. Organizer 2 CANNOT delete Organizer 1's event (403)
        $client->request('POST', '/organizer/events/' . $event1Id . '/delete', [
            '_csrf_token' => 'any',
        ]);
        self::assertResponseStatusCodeSame(403);

        // 4. Organizer 2 CANNOT delete seeded/Admin Event #1 (403)
        $client->request('POST', '/organizer/events/1/delete', [
            '_csrf_token' => 'any',
        ]);
        self::assertResponseStatusCodeSame(403);

        // 5. Organizer 2 CAN delete his own Event 2
        $crawler = $client->request('GET', '/account');
        $delForm = $crawler->filter('form[action="/organizer/events/' . $event2Id . '/delete"]');
        self::assertCount(1, $delForm);
        $delCsrf = (string) $delForm->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/organizer/events/' . $event2Id . '/delete', [
            '_csrf_token' => $delCsrf,
        ]);
        self::assertResponseRedirects('/account');

        // Event 2 is gone (404)
        $client->request('GET', '/events/' . $event2Id);
        self::assertResponseStatusCodeSame(404);

        // 6. Admin logs in with password 1234 and CAN delete Organizer 1's Event 1
        $crawler = $client->request('GET', '/admin/login');
        $adminCsrf = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/login', [
            '_csrf_token' => $adminCsrf,
            'identity' => 'admin',
            'password' => '1234',
        ]);
        self::assertResponseRedirects('/admin');

        $crawler = $client->request('GET', '/admin/events');
        $adminDelForm = $crawler->filter('form[action="/admin/events/' . $event1Id . '/delete"]');
        self::assertCount(1, $adminDelForm);
        $adminDelCsrf = (string) $adminDelForm->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/events/' . $event1Id . '/delete', [
            '_csrf_token' => $adminDelCsrf,
            'redirect_to' => 'events',
        ]);
        self::assertResponseRedirects('/admin/events');

        // Event 1 is gone (404)
        $client->request('GET', '/events/' . $event1Id);
        self::assertResponseStatusCodeSame(404);
    }

    private function registerOrganizer(KernelBrowser $client, string $name, string $email): void
    {
        $crawler = $client->request('GET', '/account/register');
        $csrf = (string) $crawler->filter('form[action="/account/register"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account/register', [
            '_csrf_token' => $csrf,
            'role' => 'organizer',
            'organizer_password' => '1111',
            'name' => $name,
            'email' => $email,
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        self::assertResponseRedirects('/organizer/events/new');
    }

    private function createOrganizerEvent(KernelBrowser $client, string $title): int
    {
        $crawler = $client->request('GET', '/organizer/events/new');
        $token = (string) $crawler->filter('input[name="event[_token]"]')->attr('value');

        $client->request('POST', '/organizer/events/new', [
            'event' => [
                'title' => $title,
                'category' => 'comedy',
                'venue' => 'Comedy Room',
                'location' => 'Carrer X 10, Valencia',
                'totalSeats' => '20',
                'seatMapEnabled' => '1',
                'seatMapLayout' => 'saloon',
                'seatRows' => '4',
                'seatsPerRow' => '5',
                'startDate' => '2026-12-28T20:00',
                'endDate' => '2026-12-28T22:00',
                'imageUrl' => '',
                'description' => 'Delete permissions test event.',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/account');

        $crawler = $client->followRedirect();
        $href = (string) $crawler->filter('a:contains("Arrange Seats")')->first()->attr('href');
        preg_match('#/organizer/events/(\d+)/seats#', $href, $m);

        return (int) $m[1];
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
