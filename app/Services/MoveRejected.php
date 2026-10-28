<?php

namespace App\Services;

use RuntimeException;

/** En handling blev afvist. Beskeden er dansk og kan vises direkte til brugeren. */
final class MoveRejected extends RuntimeException
{
    public static function boardChanged(): self
    {
        return new self('Tavlen er ændret, siden du så den. Prøv igen.');
    }
}
