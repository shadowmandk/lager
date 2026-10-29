<?php

namespace App\Services\Microbizz;

use RuntimeException;

/** Microbizz kunne ikke nås eller svarede med en fejl. Beskeden er dansk og kan vises til admin. */
final class MicrobizzException extends RuntimeException {}
