<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\User;

/** Hvem der udfører en handling: en medarbejder ved tavlen, en admin eller et andet system (source). */
final readonly class Actor
{
    private function __construct(
        public ?int $operatorId,
        public ?int $userId,
        public ?string $source = null,
    ) {}

    public static function operator(Operator|int $operator): self
    {
        return new self($operator instanceof Operator ? $operator->id : $operator, null);
    }

    public static function admin(User|int $user): self
    {
        return new self(null, $user instanceof User ? $user->id : $user);
    }

    /** Hentning fra Microbizz. */
    public static function microbizz(): self
    {
        return new self(null, null, 'microbizz');
    }
}
