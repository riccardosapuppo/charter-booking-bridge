<?php

declare(strict_types=1);

namespace Charter;

/**
 * The optional things: a skipper, bed linen, a gennaker, an outboard.
 *
 * Two separate mistakes met here, and they met on the money.
 *
 * The first is that nobody checked. The ids came out of the browser, were cast
 * to positive integers, and were forwarded to the operator without anybody
 * asking whether that boat offers that extra, in that season, from that base,
 * for a week that long. The check existed — the original had a function that
 * did all four of those things, written out in full, twenty-three lines — and
 * it was never called from anywhere. It sat there looking like a defence.
 *
 * The second is the quantity. The page multiplies: bed linen is priced per
 * person and the boat sleeps six, so the total on the screen went up by six
 * times thirty-five. The request then sent `quantity: 1`, and the bridge threw
 * even that away and forwarded a bare number. So the visitor was shown one
 * figure and the operator booked another, and neither side had anything that
 * could notice.
 */
final class Extras
{
    /**
     * The extras this boat offers this season, as the site should show them.
     *
     * @param array<string,mixed> $boat
     *
     * @return list<array<string,mixed>>
     */
    public static function offered(array $boat): array
    {
        $season = $boat['seasonSpecificData'][0] ?? [];
        $offered = [];

        foreach ((array) ($season['services'] ?? []) as $one) {
            $offered[] = ['family' => 'service', 'id' => (int) $one['serviceId']] + $one;
        }

        foreach ((array) ($season['additionalYachtEquipment'] ?? []) as $one) {
            $offered[] = ['family' => 'equipment', 'id' => (int) $one['equipmentId']] + $one;
        }

        return $offered;
    }

    /**
     * What the visitor asked for, checked against what the boat has, with the
     * quantity that goes with each line.
     *
     * @param array<string,mixed> $boat
     * @param string $family 'services' or 'equipment'
     * @param mixed $asked what arrived from the browser
     *
     * @return array{lines:list<array<string,int>>,refused:list<int>} the lines to
     *     send, and the ids this boat does not offer for this week
     */
    public static function pick(array $boat, string $family, mixed $asked, ?int $baseId, Week $week): array
    {
        $idKey = $family === 'services' ? 'serviceId' : 'equipmentId';
        $wanted = $family === 'services' ? 'service' : 'equipment';

        $lines = [];
        $refused = [];

        foreach (is_array($asked) ? $asked : [] as $one) {
            $id = is_array($one) ? (int) ($one[$idKey] ?? $one['id'] ?? 0) : (int) $one;

            if ($id <= 0) {
                continue;
            }

            $offer = null;

            foreach (self::offered($boat) as $candidate) {
                if ($candidate['family'] === $wanted && $candidate['id'] === $id) {
                    $offer = $candidate;

                    break;
                }
            }

            if ($offer === null || !self::availableOn($offer, $baseId, $week)) {
                $refused[] = $id;

                continue;
            }

            $lines[] = [$idKey => $id, 'quantity' => self::quantity($offer)];
        }

        return ['lines' => $lines, 'refused' => $refused];
    }

    /**
     * How many of this extra a booking takes. The offer carries it: one tender,
     * six sets of linen for a boat that sleeps six.
     *
     * The bound is the page's, kept deliberately: it refused to multiply by
     * anything above thirty, and a quantity that large is a data error rather
     * than an order.
     *
     * @param array<string,mixed> $offer
     */
    public static function quantity(array $offer): int
    {
        $amount = $offer['amount'] ?? 1;

        if (!is_numeric($amount)) {
            return 1;
        }

        $amount = (int) $amount;

        return $amount > 1 && $amount <= 30 ? $amount : 1;
    }

    /**
     * The check the original wrote and never called: is this extra on offer for
     * this period, this length of charter, and this base.
     *
     * @param array<string,mixed> $offer
     */
    public static function availableOn(array $offer, ?int $baseId, Week $week): bool
    {
        $from = Dates::day($offer['validPeriodFrom'] ?? null);
        $to = Dates::day($offer['validPeriodTo'] ?? null);

        if ($from !== null && $week->starts < $from) {
            return false;
        }

        if ($to !== null && $week->ends > $to) {
            return false;
        }

        $least = (int) ($offer['minDuration'] ?? 0);
        $most = (int) ($offer['maxDuration'] ?? 0);

        if ($week->days > 0) {
            if ($least > 0 && $week->days < $least) {
                return false;
            }

            if ($most > 0 && $week->days > $most) {
                return false;
            }
        }

        $bases = $offer['validForBases'] ?? null;

        if (is_array($bases) && $bases !== []) {
            return $baseId !== null && in_array($baseId, array_map('intval', $bases), true);
        }

        return true;
    }
}
