<?php

declare(strict_types=1);

namespace Charter;

/**
 * An invented operator, with invented boats, in invented places.
 *
 * None of this is anybody's. The operator number is 900001 because it is not a
 * number the manager would issue; the boats are the phonetic alphabet; the bay
 * and the marina do not exist; the promo code is spelled DEMO so that nobody
 * can mistake it for one somebody could spend. Every figure in the README is
 * measured against this fleet and nothing else.
 */
final class Fleet
{
    public const OPERATOR = 900001;

    /** The one promo code the invented manager honours. Ten per cent. */
    public const PROMO = 'DEMO-PROMO-10';

    /** @return array<string,mixed> */
    public static function equipment(): array
    {
        return [
            ['id' => 21, 'name' => ['textIT' => 'Randa', 'textEN' => 'Mainsail'], 'categoryId' => 1],
            ['id' => 22, 'name' => ['textIT' => 'Chartplotter', 'textEN' => 'Chartplotter'], 'categoryId' => 2],
            ['id' => 23, 'name' => ['textIT' => 'Zattera di salvataggio', 'textEN' => 'Liferaft'], 'categoryId' => 3],
            ['id' => 24, 'name' => ['textIT' => 'Gennaker', 'textEN' => 'Gennaker'], 'categoryId' => 1],
            ['id' => 31, 'name' => ['textIT' => 'Biancheria', 'textEN' => 'Bed linen'], 'categoryId' => 4],
            ['id' => 32, 'name' => ['textIT' => 'Tender', 'textEN' => 'Tender'], 'categoryId' => 4],
        ];
    }

    /** @return array<string,mixed> */
    public static function equipmentCategories(): array
    {
        return [
            ['id' => 1, 'name' => ['textIT' => 'Vele', 'textEN' => 'Sails']],
            ['id' => 2, 'name' => ['textIT' => 'Navigazione', 'textEN' => 'Navigation']],
            ['id' => 3, 'name' => ['textIT' => 'Sicurezza', 'textEN' => 'Safety']],
            ['id' => 4, 'name' => ['textIT' => 'Comfort', 'textEN' => 'Comfort']],
        ];
    }

    /** @return array<string,mixed> */
    public static function services(): array
    {
        return [
            ['id' => 77, 'name' => ['textIT' => 'Skipper', 'textEN' => 'Skipper']],
            ['id' => 78, 'name' => ['textIT' => 'Pulizia finale', 'textEN' => 'Final cleaning']],
            ['id' => 79, 'name' => ['textIT' => 'Transfer', 'textEN' => 'Transfer']],
        ];
    }

    /** @return array<string,mixed> */
    public static function bases(): array
    {
        return [['id' => 3001, 'name' => ['textIT' => 'Marina Nessuno', 'textEN' => 'Marina Nessuno']]];
    }

    /** @return array<string,mixed> */
    public static function locations(): array
    {
        return [
            ['id' => 4001, 'name' => ['textIT' => 'Porto Finto', 'textEN' => 'Porto Finto']],
            ['id' => 4002, 'name' => ['textIT' => 'Baia Inventata', 'textEN' => 'Invented Bay']],
        ];
    }

    /** @return array<string,mixed> */
    public static function builders(): array
    {
        return [['id' => 88, 'name' => 'Cantiere Immaginario']];
    }

    /** @return array<string,mixed> */
    public static function yachtCategories(): array
    {
        return [
            ['id' => 51, 'name' => ['textIT' => 'Monoscafo a vela', 'textEN' => 'Sailing monohull']],
            ['id' => 52, 'name' => ['textIT' => 'Catamarano', 'textEN' => 'Catamaran']],
        ];
    }

    /** @return array<string,mixed> */
    public static function seasons(): array
    {
        return [['id' => 2026, 'name' => 'Season 2026']];
    }

    /** @return array<string,mixed> */
    public static function countries(): array
    {
        return [
            ['id' => 501, 'name' => ['textIT' => 'Italia', 'textEN' => 'Italy']],
            ['id' => 502, 'name' => ['textIT' => 'Croazia', 'textEN' => 'Croatia']],
            ['id' => 503, 'name' => ['textIT' => 'Grecia', 'textEN' => 'Greece']],
        ];
    }

    /** @return array<string,mixed> */
    public static function models(): array
    {
        return [
            [
                'id' => 700,
                'name' => 'Invented 42',
                'yachtBuilderId' => 88,
                'yachtCategoryId' => 51,
                'loa' => 12.8,
                'virtualLength' => 42,
                'beam' => 4.2,
                'waterTank' => 530,
                'fuelTank' => 200,
            ],
            [
                'id' => 701,
                'name' => 'Invented Cat 45',
                'yachtBuilderId' => 88,
                'yachtCategoryId' => 52,
                'loa' => 13.7,
                'virtualLength' => 45,
                'beam' => 7.5,
                'waterTank' => 700,
                'fuelTank' => 400,
            ],
        ];
    }

    /**
     * The operator's fleet. Six boats, so that a carousel taking the newest ten
     * and a promotion filter have something to disagree about.
     *
     * @return list<array<string,mixed>>
     */
    public static function yachts(): array
    {
        $boats = [
            [5001, 'Alfa', 2023, 700],
            [5002, 'Bravo', 2022, 700],
            [5003, 'Charlie', 2021, 701],
            [5004, 'Delta', 2019, 700],
            [5005, 'Echo', 2016, 701],
            [5006, 'Foxtrot', 2014, 700],
        ];

        $fleet = [];

        foreach ($boats as [$id, $name, $year, $model]) {
            $fleet[] = [
                'id' => $id,
                'name' => $name,
                'buildYear' => $year,
                'yachtModelId' => $model,
                'charterCompanyId' => self::OPERATOR,
                'baseId' => 3001,
                'locationId' => 4001,
                'cabins' => 4,
                'wc' => 2,
                'berthsTotal' => 10,
                'berthsCabin' => 8,
                'berthsSalon' => 2,
                'engines' => 1,
                'enginePower' => 54,
                'draft' => 2.1,
                'mainPictureUrl' => 'https://pictures.example/invented/' . $id . '.jpg',
                'standardYachtEquipment' => [
                    ['equipmentId' => 21],
                    ['equipmentId' => 22],
                    ['equipmentId' => 23],
                ],
                'seasonSpecificData' => [[
                    'seasonId' => 2026,
                    'additionalYachtEquipment' => [
                        ['id' => 9101, 'equipmentId' => 24, 'price' => '180.00', 'currency' => 'EUR', 'calculationType' => 'FIXED', 'amount' => 1],
                        ['id' => 9102, 'equipmentId' => 31, 'price' => '35.00', 'currency' => 'EUR', 'calculationType' => 'PER_PERSON', 'amount' => 6],
                        ['id' => 9103, 'equipmentId' => 32, 'price' => '250.00', 'currency' => 'EUR', 'calculationType' => 'FIXED', 'amount' => 1],
                    ],
                    'services' => [
                        ['id' => 9201, 'serviceId' => 77, 'price' => '1200.00', 'currency' => 'EUR', 'calculationType' => 'FIXED', 'amount' => 1],
                        ['id' => 9202, 'serviceId' => 78, 'price' => '190.00', 'currency' => 'EUR', 'calculationType' => 'FIXED', 'amount' => 1, 'obligatory' => true],
                    ],
                ]],
            ];
        }

        return $fleet;
    }

    /**
     * What a week on each boat costs, and what the list price was before the
     * discount. The two 2016-and-older boats are the discounted ones, which is
     * how discounting works: it is the unsold end of the fleet.
     *
     * @return array<int,array{price:float,list:float,discount:float|null}>
     */
    public static function prices(): array
    {
        return [
            5001 => ['price' => 6400.0, 'list' => 6400.0, 'discount' => null],
            5002 => ['price' => 5900.0, 'list' => 5900.0, 'discount' => null],
            5003 => ['price' => 8200.0, 'list' => 8200.0, 'discount' => null],
            5004 => ['price' => 4800.0, 'list' => 4800.0, 'discount' => null],
            5005 => ['price' => 5100.0, 'list' => 6000.0, 'discount' => 15.0],
            5006 => ['price' => 3400.0, 'list' => 4000.0, 'discount' => 15.0],
        ];
    }
}
