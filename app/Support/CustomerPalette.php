<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Fast farve pr. kunde. Farven gemmes på kunden (`customers.color` er et indeks i COLORS), så den ikke skifter.
 * Nye kunder får den farve, færrest kunder har. Admin kan vælge en anden under Opsætning.
 * Farverne er valgt med mørk tekst og høj kontrast, så de kan læses i sollys.
 */
final class CustomerPalette
{
    /** @var list<array{side: string, top: string, end: string, stroke: string, text: string}> */
    public const COLORS = [
        ['side' => '#5B9BD5', 'top' => '#8DB9E3', 'end' => '#3F7DB8', 'stroke' => '#1F4E79', 'text' => '#0B2540'],
        ['side' => '#E07A3F', 'top' => '#EDA27A', 'end' => '#BF5E25', 'stroke' => '#7A3510', 'text' => '#3D1A06'],
        ['side' => '#5FAE5A', 'top' => '#8CCB88', 'end' => '#438F3F', 'stroke' => '#25561F', 'text' => '#0F2A0C'],
        ['side' => '#C9A227', 'top' => '#E0C25E', 'end' => '#A5831A', 'stroke' => '#5E4A0A', 'text' => '#2E2403'],
        ['side' => '#9B6FC7', 'top' => '#BD9DDD', 'end' => '#7C51A9', 'stroke' => '#452A66', 'text' => '#221333'],
        ['side' => '#D9534F', 'top' => '#E88A87', 'end' => '#B53A36', 'stroke' => '#6E1C19', 'text' => '#380B09'],
        ['side' => '#3BAFA8', 'top' => '#78CCC7', 'end' => '#258F89', 'stroke' => '#12524E', 'text' => '#062A28'],
        ['side' => '#D673A6', 'top' => '#E6A2C5', 'end' => '#B65286', 'stroke' => '#6B2A4B', 'text' => '#361224'],
        ['side' => '#8C9A3A', 'top' => '#B1BE6A', 'end' => '#6F7C24', 'stroke' => '#3E4610', 'text' => '#1E2305'],
        ['side' => '#4F6FB0', 'top' => '#8297CB', 'end' => '#3A5592', 'stroke' => '#1D2F57', 'text' => '#0C1730'],
        ['side' => '#B5835A', 'top' => '#CFA885', 'end' => '#946440', 'stroke' => '#55361F', 'text' => '#2B190C'],
        ['side' => '#5C8A8A', 'top' => '#8DB0B0', 'end' => '#436E6E', 'stroke' => '#223F3F', 'text' => '#0E2020'],
        ['side' => '#4FC3E8', 'top' => '#86D7F0', 'end' => '#2FA3C8', 'stroke' => '#135A70', 'text' => '#062B36'],
        ['side' => '#9CCB3B', 'top' => '#BEDE78', 'end' => '#7DAA24', 'stroke' => '#40590F', 'text' => '#1E2A06'],
        ['side' => '#F08C7A', 'top' => '#F5B1A4', 'end' => '#D36A57', 'stroke' => '#7A2E21', 'text' => '#3B130C'],
        ['side' => '#E8A93A', 'top' => '#F0C574', 'end' => '#C88B20', 'stroke' => '#6E4A0B', 'text' => '#352304'],
        ['side' => '#B39DDB', 'top' => '#CDBFE8', 'end' => '#9479C4', 'stroke' => '#4D3A73', 'text' => '#251B38'],
        ['side' => '#C2559E', 'top' => '#D78BBD', 'end' => '#A13C80', 'stroke' => '#5E1F4A', 'text' => '#2E0E24'],
        ['side' => '#3E8E5E', 'top' => '#74B08A', 'end' => '#2A7048', 'stroke' => '#14402A', 'text' => '#062014'],
        ['side' => '#B9603A', 'top' => '#D08B6C', 'end' => '#994A27', 'stroke' => '#5A2810', 'text' => '#2C1206'],
        ['side' => '#7A9CC6', 'top' => '#A5BEDB', 'end' => '#5C7FAB', 'stroke' => '#2E4466', 'text' => '#141F33'],
        ['side' => '#D4B483', 'top' => '#E3CCA8', 'end' => '#B8976A', 'stroke' => '#6B5230', 'text' => '#332612'],
        ['side' => '#6CC9A1', 'top' => '#9DDCBF', 'end' => '#4CAA82', 'stroke' => '#22604A', 'text' => '#0D2E22'],
        ['side' => '#7E4E7E', 'top' => '#A47CA4', 'end' => '#633A63', 'stroke' => '#3A1E3A', 'text' => '#1E0C1E'],
    ];

    /** Neutral grå til containere uden kunde, der ikke står på lager (venter eller udleveret). */
    public const NONE = ['side' => '#A7ABB0', 'top' => '#C8CBCF', 'end' => '#878B91', 'stroke' => '#4A4E54', 'text' => '#1E2125'];

    /** Lys, næsten hvid: container på lager uden kunde, ledig til udlejning. Må ikke ligne en kundefarve. */
    public const AVAILABLE = ['side' => '#E2E8F0', 'top' => '#F8FAFC', 'end' => '#CBD5E1', 'stroke' => '#475569', 'text' => '#1E293B'];

    /** @return array{side: string, top: string, end: string, stroke: string, text: string} */
    public static function for(?Customer $customer): array
    {
        if ($customer === null) {
            return self::NONE;
        }

        return self::COLORS[self::index($customer)];
    }

    /** Kundens farve som indeks. Uden gemt farve bruges en fast farve ud fra kundenummeret. */
    public static function index(Customer $customer): int
    {
        $i = $customer->color;

        return is_int($i) && isset(self::COLORS[$i]) ? $i : crc32((string) $customer->number) % count(self::COLORS);
    }

    /** Den farve, færrest kunder har. Ved lighed den første i paletten. */
    public static function leastUsed(): int
    {
        $usage = self::usage();

        return array_keys($usage, min($usage), true)[0];
    }

    /** @return array<int, int> antal kunder pr. farve */
    public static function usage(): array
    {
        $usage = array_fill(0, count(self::COLORS), 0);

        foreach (DB::table('customers')->whereNotNull('color')->selectRaw('color, count(*) as n')->groupBy('color')->pluck('n', 'color') as $i => $n) {
            if (isset($usage[(int) $i])) {
                $usage[(int) $i] = (int) $n;
            }
        }

        return $usage;
    }
}
