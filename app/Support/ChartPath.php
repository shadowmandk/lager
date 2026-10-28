<?php

namespace App\Support;

/** SVG-stier til søjlediagrammer: søjler med 4 px runde hjørner i toppen og flad bund på grundlinjen. */
final class ChartPath
{
    public static function bar(float $x, float $y, float $w, float $h, float $r = 4): string
    {
        $r = max(0, min($r, $w / 2, $h));
        $f = fn (float $n) => round($n, 2);

        return 'M'.$f($x).' '.$f($y + $h)
            .'V'.$f($y + $r)
            .'Q'.$f($x).' '.$f($y).' '.$f($x + $r).' '.$f($y)
            .'H'.$f($x + $w - $r)
            .'Q'.$f($x + $w).' '.$f($y).' '.$f($x + $w).' '.$f($y + $r)
            .'V'.$f($y + $h).'Z';
    }
}
