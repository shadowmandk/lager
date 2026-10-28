<?php

namespace App\Support;

use App\Models\Container;
use App\Services\MoveRejected;

/**
 * Udfolder en nummerserie: "4101" til "4120", eller "MSKU001" til "MSKU020" med samme forbogstaver.
 * Starter fra-nummeret med 0, beholdes antallet af cifre.
 */
final class NumberSeries
{
    public const MAX = 200;

    /** @return list<string> */
    public static function expand(string $from, string $to): array
    {
        $from = Container::normalizeNumber($from);
        $to = Container::normalizeNumber($to);

        if (! preg_match('/^(.*?)(\d+)$/', $from, $a) || ! preg_match('/^(.*?)(\d+)$/', $to, $b)) {
            throw new MoveRejected('Fra og til skal slutte med cifre, f.eks. 4101 til 4120.');
        }

        if ($a[1] !== $b[1]) {
            throw new MoveRejected('Fra og til skal starte ens, f.eks. MSKU001 til MSKU020.');
        }

        $start = (int) $a[2];
        $end = (int) $b[2];

        if ($end < $start) {
            throw new MoveRejected('Til-nummeret skal være større end eller lig med fra-nummeret.');
        }

        if ($end - $start + 1 > self::MAX) {
            throw new MoveRejected('Der kan højst oprettes '.self::MAX.' containere ad gangen.');
        }

        $width = str_starts_with($a[2], '0') ? strlen($a[2]) : 0;

        return array_map(
            fn (int $n) => $a[1].($width > 0 ? str_pad((string) $n, $width, '0', STR_PAD_LEFT) : (string) $n),
            range($start, $end),
        );
    }
}
