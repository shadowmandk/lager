<?php

namespace App\Services;

use App\Models\Container;
use App\Models\StorageRow;

/**
 * Øjebliksbillede af én række: hvilke pladser er optaget af hvilke containere.
 *
 * Al spærrelogik ligger her. Dybde 1 er forrest ved indgangen, højde 1 er nederst.
 * En række kan kun tilgås fra dybde 1, og containere står stablet, så:
 *  - man kan kun tage den øverste container i den forreste ikke-tomme kolonne,
 *  - man kan kun sætte en container, hvis alt foran er tomt og den står på gulvet eller på en anden container.
 */
final class RowGrid
{
    /** @var array<int, array<int, Container>> [dybde][højde] => container */
    private array $cells = [];

    private function __construct(public readonly StorageRow $row) {}

    public static function load(StorageRow $row): self
    {
        $containers = Container::query()
            ->whereIn('status', Container::ON_SLOT)
            ->where('storage_row_id', $row->id)
            ->get();

        return self::fromContainers($row, $containers);
    }

    /**
     * Containere i transit optager deres reserverede plads, men står der ikke endnu:
     * de kan ikke tages, og intet kan sættes oven på dem.
     *
     * @param  iterable<Container>  $containers
     */
    public static function fromContainers(StorageRow $row, iterable $containers): self
    {
        $grid = new self($row);

        foreach ($containers as $container) {
            if ($container->occupiesSlot() && $container->storage_row_id === $row->id) {
                $grid->cells[$container->depth][$container->level] = $container;
            }
        }

        return $grid;
    }

    /** Samme række, som om containeren var løftet ud. */
    public function without(?int $containerId): self
    {
        if ($containerId === null) {
            return $this;
        }

        $copy = clone $this;

        foreach ($copy->cells as $depth => $column) {
            foreach ($column as $level => $container) {
                if ($container->id === $containerId) {
                    unset($copy->cells[$depth][$level]);
                }
            }
        }

        return $copy;
    }

    /** Samme række med containeren sat på pladsen. Bruges, når en plan regnes igennem trin for trin. */
    public function with(Container $container, int $depth, int $level): self
    {
        $copy = clone $this;
        $copy->cells[$depth][$level] = $container;

        return $copy;
    }

    /**
     * Hvor mange containere får én mere i vejen, hvis der sættes en container på pladsen?
     * Det er dem nedenunder i samme kolonne og alle i kolonnerne bagved.
     */
    public function buriedBy(int $depth, int $level): int
    {
        $buried = 0;

        foreach ($this->cells as $d => $column) {
            foreach (array_keys($column) as $l) {
                if ($d > $depth || ($d === $depth && $l < $level)) {
                    $buried++;
                }
            }
        }

        return $buried;
    }

    /**
     * Den ledige container, der kan tages nu, men ikke kan, hvis der sættes en container på pladsen.
     * Kun den øverste i den forreste kolonne kan tages, så det er den, hvis pladsen er foran eller oven på den.
     */
    public function trapsAvailable(int $depth, int $level): ?Container
    {
        $top = $this->takeable();

        if ($top === null || ! $top->isAvailable()) {
            return null;
        }

        return $depth < $top->depth || ($depth === $top->depth && $level > $top->level) ? $top : null;
    }

    public function at(int $depth, int $level): ?Container
    {
        return $this->cells[$depth][$level] ?? null;
    }

    public function countInColumn(int $depth): int
    {
        return count($this->cells[$depth] ?? []);
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->cells));
    }

    /** Den forreste kolonne med mindst én container, eller null hvis rækken er tom. */
    public function frontDepth(): ?int
    {
        $depths = array_keys(array_filter($this->cells, fn (array $column) => $column !== []));

        return $depths === [] ? null : min($depths);
    }

    /** Den eneste container i rækken, der kan tages lige nu. */
    public function takeable(): ?Container
    {
        $front = $this->frontDepth();

        if ($front === null) {
            return null;
        }

        $top = $this->cells[$front][max(array_keys($this->cells[$front]))];

        return $top->isInTransit() ? null : $top;
    }

    /** Står der en container i transit øverst i kolonnen? */
    private function transitOnTop(int $depth): bool
    {
        $column = $this->cells[$depth] ?? [];

        return $column !== [] && $column[max(array_keys($column))]->isInTransit();
    }

    /**
     * Containere, der skal flyttes, før den givne kan tages, i den rækkefølge de kan tages:
     * forreste kolonne først, oppefra og ned, og til sidst dem ovenpå i containerens egen kolonne.
     *
     * @return list<Container>
     */
    public function blockersFor(Container $target): array
    {
        $blockers = [];

        $depths = array_keys($this->cells);
        sort($depths);

        foreach ($depths as $depth) {
            if ($depth > $target->depth) {
                break;
            }

            $levels = array_keys($this->cells[$depth]);
            rsort($levels);

            foreach ($levels as $level) {
                if ($depth < $target->depth || $level > $target->level) {
                    $blockers[] = $this->cells[$depth][$level];
                }
            }
        }

        return $blockers;
    }

    public function whyCannotPlace(int $depth, int $level): ?string
    {
        if ($depth < 1 || $depth > $this->row->depth || $level < 1 || $level > $this->row->height) {
            return 'Pladsen findes ikke i rækken.';
        }

        if ($this->at($depth, $level) !== null) {
            return 'Pladsen er optaget.';
        }

        $front = $this->frontDepth();

        if ($front !== null && $front < $depth) {
            return 'Der står containere foran pladsen.';
        }

        if ($level > 1 && $this->at($depth, $level - 1) === null) {
            return 'Der står ingen container under pladsen.';
        }

        if ($level > 1 && $this->at($depth, $level - 1)->isInTransit()) {
            return 'Containeren nedenunder er i transit og er ikke meldt sat i buffer endnu.';
        }

        return null;
    }

    public function canPlace(int $depth, int $level): bool
    {
        return $this->whyCannotPlace($depth, $level) === null;
    }

    /** @return list<array{0: int, 1: int}> lovlige [dybde, højde] lige nu */
    public function legalTargets(): array
    {
        $front = $this->frontDepth();
        $targets = [];

        $lastEmpty = $front === null ? $this->row->depth : $front - 1;

        for ($depth = 1; $depth <= $lastEmpty; $depth++) {
            $targets[] = [$depth, 1];
        }

        if ($front !== null) {
            $next = $this->countInColumn($front) + 1;

            if ($next <= $this->row->height && $this->canPlace($front, $next)) {
                $targets[] = [$front, $next];
            }
        }

        return $targets;
    }

    /**
     * Antal tomme pladser, der bliver spærret inde, hvis man sætter en container i kolonnen.
     * Pladser, der allerede er spærret af en container længere fremme, tæller ikke med.
     */
    public function blockedBehind(int $depth): int
    {
        $front = $this->frontDepth() ?? $this->row->depth + 1;

        if ($depth >= $front) {
            return 0;
        }

        $blocked = 0;

        for ($d = $depth + 1; $d <= min($front, $this->row->depth); $d++) {
            $blocked += $this->row->height - $this->countInColumn($d);
        }

        return $blocked;
    }

    /** Tomme pladser, der kan nås uden at flytte noget i rækken. */
    public function reachableFree(): int
    {
        $front = $this->frontDepth();

        if ($front === null) {
            return $this->row->capacity();
        }

        $onTop = $this->transitOnTop($front) ? 0 : $this->row->height - $this->countInColumn($front);

        return ($front - 1) * $this->row->height + $onTop;
    }
}
