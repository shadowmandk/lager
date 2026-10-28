<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Container;
use App\Models\StorageRow;
use App\Support\NumberSeries;
use App\Support\Slot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Udfører alle ændringer af containeres placering, status, navn og kunde.
 * Hver handling sker i én transaktion, tjekker reglerne på ny og skriver én logpost.
 */
final class MoveService
{
    public function __construct(private readonly PlacementRules $rules) {}

    /** Flyt en container fra én plads til en anden. */
    public function move(Container $container, Slot $expected, Slot $target, Actor $actor): Activity
    {
        return $this->transaction(function () use ($container, $expected, $target, $actor) {
            $container = $this->fresh($container);

            if (! $container->isStored() || ! $expected->equals($container->slot())) {
                throw MoveRejected::boardChanged();
            }

            if ($target->equals($expected)) {
                throw new MoveRejected('Containeren står allerede på den plads.');
            }

            $this->assertCanTake($container);
            $this->assertCanPlace($target, $container->id);

            return $this->apply($container, Activity::MOVED, $actor, slot: $target);
        });
    }

    /** Udlevér en container til kunden. */
    public function deliver(Container $container, Slot $expected, Actor $actor): Activity
    {
        return $this->transaction(function () use ($container, $expected, $actor) {
            $container = $this->fresh($container);

            if (! $container->isStored() || ! $expected->equals($container->slot())) {
                throw MoveRejected::boardChanged();
            }

            $this->assertCanTake($container);

            return $this->apply($container, Activity::DELIVERED, $actor, status: Container::DELIVERED, slot: null);
        });
    }

    /** En udleveret container kommer hjem og sættes på en plads. Kunden kan skiftes, medmindre Microbizz bestemmer den. */
    public function returnHome(Container $container, Slot $target, ?int $customerId, Actor $actor): Activity
    {
        return $this->transaction(function () use ($container, $target, $customerId, $actor) {
            $container = $this->fresh($container);

            if ($container->status !== Container::DELIVERED) {
                throw MoveRejected::boardChanged();
            }

            $this->assertCanPlace($target);

            if ($container->isFromMicrobizz()) {
                $customerId = $container->customer_id;
            }

            return $this->apply($container, Activity::RETURNED, $actor,
                status: Container::STORED, slot: $target, customerId: $customerId);
        });
    }

    /** Første placering af en ny container. */
    public function place(Container $container, Slot $target, Actor $actor): Activity
    {
        return $this->transaction(function () use ($container, $target, $actor) {
            $container = $this->fresh($container);

            if ($container->status !== Container::PENDING) {
                throw MoveRejected::boardChanged();
            }

            $this->assertCanPlace($target);

            return $this->apply($container, Activity::PLACED, $actor, status: Container::STORED, slot: $target);
        });
    }

    /**
     * Send en container til bufferen på et andet lager. Den forlader sin plads og står i transit
     * med den reserverede bufferplads, til den meldes sat i buffer.
     */
    public function transfer(Container $container, Slot $expected, Slot $reserved, Actor $actor): Activity
    {
        return $this->transaction(function () use ($container, $expected, $reserved, $actor) {
            $container = $this->fresh($container);

            if (! $container->isStored() || ! $expected->equals($container->slot())) {
                throw MoveRejected::boardChanged();
            }

            $this->assertCanTake($container);

            if (! app(TransferPlanner::class)->isBufferElsewhere($container, $reserved)) {
                throw new MoveRejected('Containeren kan kun sendes til bufferen på et andet lager.');
            }

            $this->assertCanPlace($reserved, $container->id);

            return $this->apply($container, Activity::TRANSFERRED, $actor, status: Container::TRANSIT, slot: $reserved);
        });
    }

    /** En container i transit er sat ned på sin reserverede bufferplads. */
    public function arrive(Container $container, Slot $expected, Actor $actor): Activity
    {
        return $this->transaction(function () use ($container, $expected, $actor) {
            $container = $this->fresh($container);

            if (! $container->isInTransit() || ! $expected->equals($container->location())) {
                throw MoveRejected::boardChanged();
            }

            return $this->apply($container, Activity::ARRIVED, $actor, status: Container::STORED);
        });
    }

    /** Ret navn og kunde. */
    public function edit(Container $container, ?string $name, ?int $customerId, Actor $actor): ?Activity
    {
        return $this->transaction(function () use ($container, $name, $customerId, $actor) {
            $container = $this->fresh($container);
            $name = $this->cleanName($name);

            if ($container->isFromMicrobizz()) {
                throw new MoveRejected(MoveRejected::MICROBIZZ);
            }

            if ($container->name === $name && $container->customer_id === $customerId) {
                return null;
            }

            return $this->apply($container, Activity::EDITED, $actor, name: $name, customerId: $customerId);
        });
    }

    /** Opret en ny container, enten som "venter på plads" eller som udleveret. */
    public function create(string $number, ?string $name, ?int $customerId, string $status, Actor $actor): Container
    {
        if (! in_array($status, [Container::PENDING, Container::DELIVERED], true)) {
            throw new MoveRejected('En ny container skal enten vente på plads eller være udleveret.');
        }

        $number = Container::normalizeNumber($number);

        if ($number === '') {
            throw new MoveRejected('Containeren skal have et nummer.');
        }

        return $this->transaction(function () use ($number, $name, $customerId, $status, $actor) {
            if (Container::withTrashed()->whereRaw('upper(number) = ?', [$number])->exists()) {
                throw new MoveRejected("Der findes allerede en container med nummer {$number}.");
            }

            $container = Container::query()->create([
                'number' => $number,
                'name' => $this->cleanName($name),
                'customer_id' => $customerId,
                'status' => $status,
            ]);

            $this->log($container, Activity::CREATED, $actor, before: null);

            return $container;
        });
    }

    /**
     * Opret en hel nummerserie, f.eks. 4101 til 4120, med samme navn, kunde og status.
     * Numre, der allerede findes, springes over.
     *
     * @return array{created: list<Container>, skipped: list<string>}
     */
    public function createSeries(string $from, string $to, ?string $name, ?int $customerId, string $status, Actor $actor): array
    {
        $numbers = NumberSeries::expand($from, $to);

        return $this->transaction(function () use ($numbers, $name, $customerId, $status, $actor) {
            $created = [];
            $skipped = [];

            foreach ($numbers as $number) {
                if (Container::withTrashed()->whereRaw('upper(number) = ?', [$number])->exists()) {
                    $skipped[] = $number;

                    continue;
                }

                $created[] = $this->create($number, $name, $customerId, $status, $actor);
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Fælles byggesten: ændrer containeren og skriver logposten.
     * Bruges også af UndoService, når en handling rulles tilbage.
     *
     * @internal
     */
    public function apply(
        Container $container,
        string $action,
        Actor $actor,
        ?string $status = null,
        Slot|null|false $slot = false,
        int|null|false $customerId = false,
        string|null|false $name = false,
        ?int $undoesId = null,
    ): Activity {
        $before = $this->snapshot($container);

        if ($status !== null) {
            $container->status = $status;
        }

        if ($slot !== false) {
            $container->storage_row_id = $slot?->rowId;
            $container->depth = $slot?->depth;
            $container->level = $slot?->level;
        }

        if ($customerId !== false) {
            $container->customer_id = $customerId;
        }

        if ($name !== false) {
            $container->name = $name;
        }

        try {
            $container->save();
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw MoveRejected::boardChanged();
            }

            throw $e;
        }

        return $this->log($container, $action, $actor, $before, $undoesId);
    }

    public function assertCanTake(Container $container): void
    {
        if ($reason = $this->rules->whyCannotTake($container)) {
            throw new MoveRejected($reason);
        }
    }

    public function assertCanPlace(Slot $target, ?int $ignoreContainerId = null): void
    {
        if ($reason = $this->rules->whyCannotPlace($target, $ignoreContainerId)) {
            throw new MoveRejected($reason);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function fresh(Container $container): Container
    {
        $fresh = Container::query()->lockForUpdate()->find($container->id);

        if ($fresh === null) {
            throw new MoveRejected('Containeren findes ikke længere.');
        }

        // Lås også de rækker, der er involveret, på databaser der understøtter det.
        if ($fresh->storage_row_id) {
            StorageRow::query()->lockForUpdate()->find($fresh->storage_row_id);
        }

        return $fresh;
    }

    /** @return array{status: string, slot: ?Slot, customer_id: ?int, name: ?string} */
    private function snapshot(Container $container): array
    {
        return [
            'status' => $container->status,
            'slot' => $container->location(),
            'customer_id' => $container->customer_id,
            'name' => $container->name,
        ];
    }

    /** @param array{status: string, slot: ?Slot, customer_id: ?int, name: ?string}|null $before */
    private function log(Container $container, string $action, Actor $actor, ?array $before, ?int $undoesId = null): Activity
    {
        $after = $this->snapshot($container);

        return Activity::query()->create([
            'operator_id' => $actor->operatorId,
            'user_id' => $actor->userId,
            'action' => $action,
            'container_id' => $container->id,
            'from_status' => $before['status'] ?? null,
            'from_row_id' => $before['slot']?->rowId ?? null,
            'from_depth' => $before['slot']?->depth ?? null,
            'from_level' => $before['slot']?->level ?? null,
            'from_label' => $before ? LocationLabel::for($before['status'], $before['slot']) : null,
            'to_status' => $after['status'],
            'to_row_id' => $after['slot']?->rowId,
            'to_depth' => $after['slot']?->depth,
            'to_level' => $after['slot']?->level,
            'to_label' => LocationLabel::for($after['status'], $after['slot']),
            'from_customer_id' => $before['customer_id'] ?? null,
            'to_customer_id' => $after['customer_id'],
            'from_name' => $before['name'] ?? null,
            'to_name' => $after['name'],
            'undoes_id' => $undoesId,
            'source' => $actor->source,
        ]);
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }
}
