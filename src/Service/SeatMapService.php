<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;

class SeatMapService
{
    public const STAGE_TOP = 'top';
    public const STAGE_BOTTOM = 'bottom';
    public const STAGE_LEFT = 'left';
    public const STAGE_RIGHT = 'right';

    public function __construct(
        private readonly TicketCatalog $ticketCatalog,
    ) {
    }

    /**
     * @param list<string> $takenSeatLabels
     * @return array{
     *     enabled: bool,
     *     is_custom: bool,
     *     layout: string,
     *     layout_label: string,
     *     stage_label: string,
     *     stage_position: string,
     *     rows_count: int,
     *     seats_per_row: int,
     *     total_seats: int,
     *     taken_count: int,
     *     available_count: int,
     *     aisle_after_cols: list<int>,
     *     zones: list<array{tier_key: string, tier_name: string, price_display: string, zone_class: string, row_range: string}>,
     *     rows: list<array{
     *         letter: string,
     *         row_number: int,
     *         tier_key: string,
     *         tier_name: string,
     *         price_display: string,
     *         zone_class: string,
     *         seats: list<array{
     *             is_empty: bool,
     *             label: string,
     *             row_letter: string,
     *             col: int,
     *             taken: bool,
     *             aisle_after: bool,
     *             tier_key: string,
     *             tier_name: string,
     *             price_display: string,
     *             zone_class: string
     *         }>
     *     }>,
     *     editor_config: array{
     *         stage_position: string,
     *         rows: int,
     *         cols: int,
     *         cells: list<array{r: int, c: int, label: string, tier: string}>
     *     }
     * }
     */
    public function buildSeatMap(Event $event, array $takenSeatLabels): array
    {
        $takenLookup = [];
        foreach ($takenSeatLabels as $label) {
            $norm = $this->normalizeSeatLabel($label);
            if ($norm !== '') {
                $takenLookup[$norm] = true;
            }
        }

        $customConfig = $event->getSeatMapConfig();
        if (
            is_array($customConfig)
            && isset($customConfig['cells'])
            && is_array($customConfig['cells'])
            && count($customConfig['cells']) > 0
        ) {
            return $this->buildCustomSeatMap($event, $customConfig, $takenLookup);
        }

        return $this->buildPresetSeatMap($event, $takenLookup);
    }

    /**
     * Parses, validates, and sanitizes custom seat map JSON from the Seat Position Editor.
     *
     * @param list<string> $lockedTakenSeats Seats already purchased by customers (preserved so booked tickets are never orphaned)
     * @return ?array{
     *     stage_position: string,
     *     rows: int,
     *     cols: int,
     *     cells: list<array{r: int, c: int, label: string, tier: string}>
     * }
     */
    public function sanitizeSeatMapConfigJson(
        ?string $rawJson,
        int $fallbackRows,
        int $fallbackCols,
        array $lockedTakenSeats = [],
    ): ?array {
        if ($rawJson === null || trim($rawJson) === '') {
            return null;
        }

        try {
            $decoded = json_decode($rawJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['cells']) || !is_array($decoded['cells'])) {
            return null;
        }

        $stagePos = (string) ($decoded['stage_position'] ?? self::STAGE_TOP);
        if (!in_array($stagePos, [self::STAGE_TOP, self::STAGE_BOTTOM, self::STAGE_LEFT, self::STAGE_RIGHT], true)) {
            $stagePos = self::STAGE_TOP;
        }

        $rows = max(1, min(26, (int) ($decoded['rows'] ?? $fallbackRows)));
        $cols = max(2, min(24, (int) ($decoded['cols'] ?? $fallbackCols)));

        $usedCoords = [];
        $usedLabels = [];
        $cells = [];

        foreach ($decoded['cells'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $r = isset($entry['r']) ? (int) $entry['r'] : -1;
            $c = isset($entry['c']) ? (int) $entry['c'] : -1;
            if ($r < 0 || $r >= $rows || $c < 0 || $c >= $cols) {
                continue;
            }

            $coordKey = $r . ':' . $c;
            if (isset($usedCoords[$coordKey])) {
                continue;
            }

            $rawLabel = $this->normalizeSeatLabel((string) ($entry['label'] ?? ''));
            if ($rawLabel === '' || preg_match('/^[A-Z0-9-]{1,12}$/', $rawLabel) !== 1) {
                $rawLabel = $this->getRowLetter($r) . ($c + 1);
            }
            if (isset($usedLabels[$rawLabel])) {
                continue;
            }

            $tierKey = (string) ($entry['tier'] ?? '');
            if (!$this->ticketCatalog->hasTier($tierKey)) {
                $tierKey = $this->resolveTierKeyForRowIndex($r, $rows);
            }

            $usedCoords[$coordKey] = true;
            $usedLabels[$rawLabel] = true;
            $cells[] = [
                'r' => $r,
                'c' => $c,
                'label' => $rawLabel,
                'tier' => $tierKey,
            ];
        }

        // Ensure any already-booked seat label remains in the layout so existing customer tickets stay intact
        foreach ($lockedTakenSeats as $locked) {
            $normLocked = $this->normalizeSeatLabel($locked);
            if ($normLocked === '' || isset($usedLabels[$normLocked])) {
                continue;
            }
            $parsed = $this->parseSeatLabel($normLocked);
            $r = $parsed !== null ? min($rows - 1, $parsed[0]) : 0;
            $c = $parsed !== null ? min($cols - 1, $parsed[1] - 1) : 0;

            // Find nearest free coordinate
            $placed = false;
            for ($rr = $r; $rr < $rows && !$placed; ++$rr) {
                for ($cc = ($rr === $r ? $c : 0); $cc < $cols && !$placed; ++$cc) {
                    if (!isset($usedCoords[$rr . ':' . $cc])) {
                        $usedCoords[$rr . ':' . $cc] = true;
                        $usedLabels[$normLocked] = true;
                        $cells[] = [
                            'r' => $rr,
                            'c' => $cc,
                            'label' => $normLocked,
                            'tier' => $this->resolveTierKeyForRowIndex($rr, $rows),
                        ];
                        $placed = true;
                    }
                }
            }
        }

        if ($cells === []) {
            return null;
        }

        usort($cells, static fn (array $a, array $b): int => ($a['r'] <=> $b['r']) ?: ($a['c'] <=> $b['c']));

        return [
            'stage_position' => $stagePos,
            'rows' => $rows,
            'cols' => $cols,
            'cells' => $cells,
        ];
    }

    public function normalizeSeatLabel(string $seatLabel): string
    {
        return strtoupper(trim($seatLabel));
    }

    public function isValidSeatLabel(Event $event, string $seatLabel): bool
    {
        $norm = $this->normalizeSeatLabel($seatLabel);
        if ($norm === '') {
            return false;
        }

        $customConfig = $event->getSeatMapConfig();
        if (
            is_array($customConfig)
            && isset($customConfig['cells'])
            && is_array($customConfig['cells'])
            && count($customConfig['cells']) > 0
        ) {
            foreach ($customConfig['cells'] as $cell) {
                if (isset($cell['label']) && $this->normalizeSeatLabel((string) $cell['label']) === $norm) {
                    return true;
                }
            }

            return false;
        }

        $parsed = $this->parseSeatLabel($norm);
        if ($parsed === null) {
            return false;
        }

        [$rowIndex, $col] = $parsed;
        $rowsCount = max(1, min(26, $event->getSeatRows()));
        $seatsPerRow = max(2, min(20, $event->getSeatsPerRow()));

        return $rowIndex >= 0
            && $rowIndex < $rowsCount
            && $col >= 1
            && $col <= $seatsPerRow;
    }

    public function getTierKeyForSeat(Event $event, string $seatLabel): string
    {
        $norm = $this->normalizeSeatLabel($seatLabel);

        $customConfig = $event->getSeatMapConfig();
        if (
            is_array($customConfig)
            && isset($customConfig['cells'])
            && is_array($customConfig['cells'])
            && count($customConfig['cells']) > 0
        ) {
            foreach ($customConfig['cells'] as $cell) {
                if (isset($cell['label']) && $this->normalizeSeatLabel((string) $cell['label']) === $norm) {
                    $tier = (string) ($cell['tier'] ?? '');

                    return $this->ticketCatalog->hasTier($tier) ? $tier : TicketCatalog::DEFAULT_TIER;
                }
            }
        }

        $parsed = $this->parseSeatLabel($norm);
        if ($parsed === null) {
            return TicketCatalog::DEFAULT_TIER;
        }

        [$rowIndex] = $parsed;
        $rowsCount = max(1, min(26, $event->getSeatRows()));

        return $this->resolveTierKeyForRowIndex($rowIndex, $rowsCount);
    }

    public function getRowLetter(int $rowIndexZeroBased): string
    {
        return chr(65 + max(0, min(25, $rowIndexZeroBased)));
    }

    /**
     * @param array<string, true> $takenLookup
     */
    private function buildPresetSeatMap(Event $event, array $takenLookup): array
    {
        $rowsCount = max(1, min(26, $event->getSeatRows()));
        $seatsPerRow = max(2, min(20, $event->getSeatsPerRow()));
        $layout = $event->getSeatMapLayout();
        $aisleAfterCols = $this->computeAisleAfterCols($layout, $seatsPerRow);

        $tiers = $this->ticketCatalog->getTiers();
        $rows = [];
        $zoneRows = [];
        $takenCount = 0;
        $editorCells = [];

        // Build editor grid columns including aisles as empty columns so the Visual Editor
        // opens with the exact same visual aisles ready to customize!
        $editorCols = $seatsPerRow + count($aisleAfterCols);

        for ($r = 0; $r < $rowsCount; ++$r) {
            $letter = $this->getRowLetter($r);
            $rowNumber = $r + 1;
            $tierKey = $this->resolveTierKeyForRowIndex($r, $rowsCount);
            $tier = $tiers[$tierKey] ?? $this->ticketCatalog->getTier($tierKey);
            $zoneClass = $this->getZoneClassForTier($tierKey);

            $zoneRows[$tierKey]['tier_key'] = $tierKey;
            $zoneRows[$tierKey]['tier_name'] = $tier['name'];
            $zoneRows[$tierKey]['price_display'] = $tier['price_display'];
            $zoneRows[$tierKey]['zone_class'] = $zoneClass;
            $zoneRows[$tierKey]['letters'][] = $letter;

            $seats = [];
            $editorColCursor = 0;
            for ($c = 1; $c <= $seatsPerRow; ++$c) {
                $seatLabel = $letter . $c;
                $isTaken = isset($takenLookup[$seatLabel]);
                if ($isTaken) {
                    ++$takenCount;
                }

                $hasAisle = in_array($c, $aisleAfterCols, true);
                $seats[] = [
                    'is_empty' => false,
                    'label' => $seatLabel,
                    'row_letter' => $letter,
                    'col' => $c,
                    'taken' => $isTaken,
                    'aisle_after' => $hasAisle,
                    'tier_key' => $tierKey,
                    'tier_name' => $tier['name'],
                    'price_display' => $tier['price_display'],
                    'zone_class' => $zoneClass,
                ];

                $editorCells[] = [
                    'r' => $r,
                    'c' => $editorColCursor,
                    'label' => $seatLabel,
                    'tier' => $tierKey,
                ];
                ++$editorColCursor;
                if ($hasAisle && $editorCols <= 24) {
                    ++$editorColCursor;
                }
            }

            $rows[] = [
                'letter' => $letter,
                'row_number' => $rowNumber,
                'tier_key' => $tierKey,
                'tier_name' => $tier['name'],
                'price_display' => $tier['price_display'],
                'zone_class' => $zoneClass,
                'seats' => $seats,
            ];
        }

        if ($editorCols > 24) {
            // Re-pack without extra aisle columns if wider than 24
            $editorCols = $seatsPerRow;
            $editorCells = [];
            for ($r = 0; $r < $rowsCount; ++$r) {
                $letter = $this->getRowLetter($r);
                $tierKey = $this->resolveTierKeyForRowIndex($r, $rowsCount);
                for ($c = 1; $c <= $seatsPerRow; ++$c) {
                    $editorCells[] = [
                        'r' => $r,
                        'c' => $c - 1,
                        'label' => $letter . $c,
                        'tier' => $tierKey,
                    ];
                }
            }
        }

        $zones = [];
        foreach ($zoneRows as $info) {
            $letters = $info['letters'];
            $range = count($letters) === 1
                ? 'Row ' . $letters[0]
                : sprintf('Rows %s–%s', $letters[0], $letters[count($letters) - 1]);
            $zones[] = [
                'tier_key' => $info['tier_key'],
                'tier_name' => $info['tier_name'],
                'price_display' => $info['price_display'],
                'zone_class' => $info['zone_class'],
                'row_range' => $range,
            ];
        }

        $totalSeats = $rowsCount * $seatsPerRow;

        return [
            'enabled' => $event->isSeatMapEnabled(),
            'is_custom' => false,
            'layout' => $layout,
            'layout_label' => $event->getSeatMapLayoutLabel(),
            'stage_label' => $this->getStageLabel($layout),
            'stage_position' => self::STAGE_TOP,
            'rows_count' => $rowsCount,
            'seats_per_row' => $seatsPerRow,
            'total_seats' => $totalSeats,
            'taken_count' => $takenCount,
            'available_count' => max(0, $totalSeats - $takenCount),
            'aisle_after_cols' => $aisleAfterCols,
            'zones' => $zones,
            'rows' => $rows,
            'editor_config' => [
                'stage_position' => self::STAGE_TOP,
                'rows' => $rowsCount,
                'cols' => $editorCols,
                'cells' => $editorCells,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, true> $takenLookup
     */
    private function buildCustomSeatMap(Event $event, array $config, array $takenLookup): array
    {
        $rowsCount = max(1, min(26, (int) ($config['rows'] ?? $event->getSeatRows())));
        $colsCount = max(2, min(24, (int) ($config['cols'] ?? $event->getSeatsPerRow())));
        $stagePos = (string) ($config['stage_position'] ?? self::STAGE_TOP);
        if (!in_array($stagePos, [self::STAGE_TOP, self::STAGE_BOTTOM, self::STAGE_LEFT, self::STAGE_RIGHT], true)) {
            $stagePos = self::STAGE_TOP;
        }

        $cellMap = [];
        $editorCells = [];
        foreach ($config['cells'] as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $r = (int) ($cell['r'] ?? -1);
            $c = (int) ($cell['c'] ?? -1);
            $label = $this->normalizeSeatLabel((string) ($cell['label'] ?? ''));
            $tierKey = (string) ($cell['tier'] ?? '');
            if ($r < 0 || $r >= $rowsCount || $c < 0 || $c >= $colsCount || $label === '') {
                continue;
            }
            if (!$this->ticketCatalog->hasTier($tierKey)) {
                $tierKey = $this->resolveTierKeyForRowIndex($r, $rowsCount);
            }
            $cellMap[$r . ':' . $c] = [
                'r' => $r,
                'c' => $c,
                'label' => $label,
                'tier' => $tierKey,
            ];
            $editorCells[] = $cellMap[$r . ':' . $c];
        }

        $tiers = $this->ticketCatalog->getTiers();
        $tierOrder = ['backstage_pass', 'vip_pass', 'full_pass', 'day_pass'];
        $zoneCounts = [];
        $rows = [];
        $totalSeats = 0;
        $takenCount = 0;

        for ($r = 0; $r < $rowsCount; ++$r) {
            $letter = $this->getRowLetter($r);
            $seats = [];
            $rowPrimaryTier = $this->resolveTierKeyForRowIndex($r, $rowsCount);
            $firstSeatTier = null;

            for ($c = 0; $c < $colsCount; ++$c) {
                $key = $r . ':' . $c;
                if (!isset($cellMap[$key])) {
                    $seats[] = [
                        'is_empty' => true,
                        'label' => '',
                        'row_letter' => $letter,
                        'col' => $c + 1,
                        'taken' => false,
                        'aisle_after' => false,
                        'tier_key' => '',
                        'tier_name' => '',
                        'price_display' => '',
                        'zone_class' => '',
                    ];
                    continue;
                }

                $cell = $cellMap[$key];
                $tierKey = $cell['tier'];
                $tier = $tiers[$tierKey] ?? $this->ticketCatalog->getTier($tierKey);
                $zoneClass = $this->getZoneClassForTier($tierKey);
                $isTaken = isset($takenLookup[$cell['label']]);

                ++$totalSeats;
                if ($isTaken) {
                    ++$takenCount;
                }
                if ($firstSeatTier === null) {
                    $firstSeatTier = $tierKey;
                }
                $zoneCounts[$tierKey] = ($zoneCounts[$tierKey] ?? 0) + 1;

                $seats[] = [
                    'is_empty' => false,
                    'label' => $cell['label'],
                    'row_letter' => $letter,
                    'col' => $c + 1,
                    'taken' => $isTaken,
                    'aisle_after' => false,
                    'tier_key' => $tierKey,
                    'tier_name' => $tier['name'],
                    'price_display' => $tier['price_display'],
                    'zone_class' => $zoneClass,
                ];
            }

            $rowTierKey = $firstSeatTier ?? $rowPrimaryTier;
            $rowTier = $tiers[$rowTierKey] ?? $this->ticketCatalog->getTier($rowTierKey);

            $rows[] = [
                'letter' => $letter,
                'row_number' => $r + 1,
                'tier_key' => $rowTierKey,
                'tier_name' => $rowTier['name'],
                'price_display' => $rowTier['price_display'],
                'zone_class' => $this->getZoneClassForTier($rowTierKey),
                'seats' => $seats,
            ];
        }

        $zones = [];
        foreach ($tierOrder as $tk) {
            if (!isset($zoneCounts[$tk])) {
                continue;
            }
            $tier = $tiers[$tk] ?? $this->ticketCatalog->getTier($tk);
            $cnt = $zoneCounts[$tk];
            $zones[] = [
                'tier_key' => $tk,
                'tier_name' => $tier['name'],
                'price_display' => $tier['price_display'],
                'zone_class' => $this->getZoneClassForTier($tk),
                'row_range' => sprintf('%d seat%s', $cnt, $cnt === 1 ? '' : 's'),
            ];
        }

        $layout = $event->getSeatMapLayout();

        return [
            'enabled' => $event->isSeatMapEnabled(),
            'is_custom' => true,
            'layout' => $layout,
            'layout_label' => 'Custom Seat Map',
            'stage_label' => $this->getStageLabel($layout),
            'stage_position' => $stagePos,
            'rows_count' => $rowsCount,
            'seats_per_row' => $colsCount,
            'total_seats' => $totalSeats,
            'taken_count' => $takenCount,
            'available_count' => max(0, $totalSeats - $takenCount),
            'aisle_after_cols' => [],
            'zones' => $zones,
            'rows' => $rows,
            'editor_config' => [
                'stage_position' => $stagePos,
                'rows' => $rowsCount,
                'cols' => $colsCount,
                'cells' => $editorCells,
            ],
        ];
    }

    private function getStageLabel(string $layout): string
    {
        return match ($layout) {
            Event::LAYOUT_SALOON => 'FRONT STAGE / PODIUM',
            Event::LAYOUT_BUILDING => 'MAIN STAGE / HALL FRONT',
            Event::LAYOUT_FESTIVAL => 'MAIN FESTIVAL STAGE',
            default => 'GRAND STAGE',
        };
    }

    /**
     * @return ?array{0: int, 1: int} [zero-based row index, 1-based column]
     */
    private function parseSeatLabel(string $seatLabel): ?array
    {
        $norm = $this->normalizeSeatLabel($seatLabel);
        if (preg_match('/^([A-Z])(\d{1,2})$/', $norm, $matches) !== 1) {
            return null;
        }

        $rowIndex = ord($matches[1]) - 65;
        $col = (int) $matches[2];
        if ($col < 1) {
            return null;
        }

        return [$rowIndex, $col];
    }

    private function resolveTierKeyForRowIndex(int $rowIndex, int $totalRows): string
    {
        if ($totalRows <= 1) {
            return 'day_pass';
        }
        if ($totalRows === 2) {
            return $rowIndex === 0 ? 'full_pass' : 'day_pass';
        }
        if ($totalRows === 3) {
            return match ($rowIndex) {
                0 => 'vip_pass',
                1 => 'full_pass',
                default => 'day_pass',
            };
        }

        if ($rowIndex === 0) {
            return 'backstage_pass';
        }

        $vipEnd = $totalRows >= 9 ? 2 : 1;
        if ($rowIndex <= $vipEnd) {
            return 'vip_pass';
        }

        $preferredEnd = (int) floor($totalRows * 0.6) - 1;
        if ($preferredEnd <= $vipEnd) {
            $preferredEnd = $vipEnd + 1;
        }

        if ($rowIndex <= $preferredEnd) {
            return 'full_pass';
        }

        return 'day_pass';
    }

    private function getZoneClassForTier(string $tierKey): string
    {
        return match ($tierKey) {
            'backstage_pass' => 'zone-royal',
            'vip_pass' => 'zone-vip',
            'full_pass' => 'zone-preferred',
            default => 'zone-standard',
        };
    }

    /**
     * @return list<int> 1-based column numbers after which an aisle gap is placed
     */
    private function computeAisleAfterCols(string $layout, int $seatsPerRow): array
    {
        if ($seatsPerRow <= 3) {
            return [];
        }

        if ($layout === Event::LAYOUT_SALOON) {
            return [(int) floor($seatsPerRow / 2)];
        }

        if ($layout === Event::LAYOUT_THEATRE) {
            if ($seatsPerRow >= 7) {
                $side = max(2, (int) floor($seatsPerRow / 4));

                return [$side, $seatsPerRow - $side];
            }

            return [(int) floor($seatsPerRow / 2)];
        }

        if ($seatsPerRow >= 9) {
            $side = max(3, (int) floor($seatsPerRow / 3));
            $rightStart = $seatsPerRow - $side;
            if ($rightStart > $side) {
                return [$side, $rightStart];
            }
        }

        return [(int) floor($seatsPerRow / 2)];
    }
}
