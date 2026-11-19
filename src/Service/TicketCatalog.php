<?php

declare(strict_types=1);

namespace App\Service;

class TicketCatalog
{
    public const DEFAULT_TIER = 'full_pass';

    /**
     * @return array<string, array{
     *     key: string,
     *     name: string,
     *     badge: string,
     *     price_cents: int,
     *     price_display: string,
     *     featured: bool,
     *     summary: string,
     *     perks: list<string>
     * }>
     */
    public function getTiers(): array
    {
        return [
            'day_pass' => [
                'key' => 'day_pass',
                'name' => 'Standard Seat / Entry',
                'badge' => 'Starter',
                'price_cents' => 1900,
                'price_display' => '19$',
                'featured' => false,
                'summary' => 'Standard admission seat or general venue entry.',
                'perks' => [
                    'Guaranteed venue entry & standard seating',
                    'Full main event access',
                    'Unique single-use QR entrance pass',
                ],
            ],
            'full_pass' => [
                'key' => 'full_pass',
                'name' => 'Preferred Front-Zone Pass',
                'badge' => 'Most Popular',
                'price_cents' => 3900,
                'price_display' => '39$',
                'featured' => true,
                'summary' => 'Preferred closer-view zone with fast-lane QR entry.',
                'perks' => [
                    'Preferred front/orchestra viewing section',
                    'Fast-lane entrance gate check-in',
                    'Access to refreshment & terrace lounges',
                ],
            ],
            'vip_pass' => [
                'key' => 'vip_pass',
                'name' => 'VIP Lounge Pass',
                'badge' => 'VIP',
                'price_cents' => 7900,
                'price_display' => '79$',
                'featured' => false,
                'summary' => 'Priority VIP gate, prime seating deck, and VIP lounge.',
                'perks' => [
                    'Dedicated priority VIP entrance',
                    'Prime reserved viewing deck / box seats',
                    'VIP bar lounge & complimentary welcome drink',
                ],
            ],
            'backstage_pass' => [
                'key' => 'backstage_pass',
                'name' => 'Backstage / Royal Box Pass',
                'badge' => 'Exclusive',
                'price_cents' => 14900,
                'price_display' => '149$',
                'featured' => false,
                'summary' => 'All VIP perks plus backstage hospitality & artist lounge.',
                'perks' => [
                    'Everything in VIP Lounge Pass',
                    'Backstage hospitality & royal box access',
                    'Exclusive artist / cast photo reception area',
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     badge: string,
     *     price_cents: int,
     *     price_display: string,
     *     featured: bool,
     *     summary: string,
     *     perks: list<string>
     * }
     */
    public function getTier(string $key): array
    {
        $tiers = $this->getTiers();

        return $tiers[$key] ?? $tiers[self::DEFAULT_TIER];
    }

    public function hasTier(string $key): bool
    {
        return array_key_exists($key, $this->getTiers());
    }

    /**
     * @return array<string, string>
     */
    public function getFormChoices(): array
    {
        $choices = [];
        foreach ($this->getTiers() as $key => $tier) {
            $choices[sprintf('%s — %s', $tier['name'], $tier['price_display'])] = $key;
        }

        return $choices;
    }
}
