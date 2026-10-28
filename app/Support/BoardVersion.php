<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * En billig fingeraftryk-streng af alt, der kan ændre tavlen.
 * Tablets og kontor spørger efter den hvert par sekunder og henter kun ny tavle, når den ændrer sig.
 */
final class BoardVersion
{
    public static function current(): string
    {
        return implode('|', [
            DB::table('activity_log')->max('id') ?? 0,
            DB::table('containers')->count(),
            DB::table('containers')->max('updated_at') ?? '',
            DB::table('storage_rows')->count(),
            DB::table('storage_rows')->max('updated_at') ?? '',
            DB::table('customers')->max('updated_at') ?? '',
        ]);
    }
}
