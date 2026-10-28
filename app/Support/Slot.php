<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * En plads i en række: dybde 1 er forrest ved indgangen, højde 1 er nederst.
 */
final readonly class Slot
{
    public function __construct(
        public int $rowId,
        public int $depth,
        public int $level,
    ) {}

    public static function fromKey(string $key): self
    {
        $parts = explode(':', $key);

        if (count($parts) !== 3 || ! collect($parts)->every(fn ($p) => ctype_digit($p))) {
            throw new InvalidArgumentException("Ugyldig plads: {$key}");
        }

        return new self((int) $parts[0], (int) $parts[1], (int) $parts[2]);
    }

    public function key(): string
    {
        return "{$this->rowId}:{$this->depth}:{$this->level}";
    }

    public function equals(?self $other): bool
    {
        return $other !== null
            && $other->rowId === $this->rowId
            && $other->depth === $this->depth
            && $other->level === $this->level;
    }
}
