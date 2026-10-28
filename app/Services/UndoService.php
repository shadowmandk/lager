<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Container;
use App\Support\Slot;
use Illuminate\Support\Collection;

/**
 * Fortryd af de seneste handlinger.
 *
 * Vinduet er de 15 nyeste fortrydbare handlinger i loggen (også dem, der allerede er fortrudt).
 * Kun den nyeste ikke-fortrudte i vinduet kan fortrydes, så rækkefølgen altid holder.
 * Fortryd kører gennem de samme regler som en almindelig flytning.
 */
final class UndoService
{
    public const WINDOW = 15;

    public function __construct(private readonly MoveService $moves) {}

    /** @return Collection<int, Activity> nyeste først */
    public function window(): Collection
    {
        return Activity::query()
            ->whereIn('action', Activity::UNDOABLE)
            ->orderByDesc('id')
            ->limit(self::WINDOW)
            ->get();
    }

    public function candidate(): ?Activity
    {
        return $this->window()->first(fn (Activity $a) => ! $a->isUndone());
    }

    public function canUndo(Activity $activity): bool
    {
        return $this->candidate()?->id === $activity->id;
    }

    public function undo(Activity $activity, Actor $actor): Activity
    {
        return $this->moves->transaction(function () use ($activity, $actor) {
            $activity = Activity::query()->lockForUpdate()->findOrFail($activity->id);

            if ($activity->isUndone()) {
                throw new MoveRejected('Handlingen er allerede fortrudt.');
            }

            if (! $this->canUndo($activity)) {
                throw new MoveRejected(
                    in_array($activity->action, Activity::UNDOABLE, true)
                        ? 'Fortryd først de nyere handlinger, eller handlingen er for gammel til at fortryde.'
                        : 'Denne handling kan ikke fortrydes.'
                );
            }

            $container = Container::withTrashed()->find($activity->container_id);

            if ($container === null || $container->trashed()) {
                throw new MoveRejected('Containeren findes ikke længere.');
            }

            $container = $this->moves->fresh($container);
            $undo = $this->reverse($activity, $container, $actor);

            $activity->undone_by_id = $undo->id;
            $activity->save();

            return $undo;
        });
    }

    private function reverse(Activity $a, Container $container, Actor $actor): Activity
    {
        $from = $a->from_row_id ? new Slot($a->from_row_id, $a->from_depth, $a->from_level) : null;
        $to = $a->to_row_id ? new Slot($a->to_row_id, $a->to_depth, $a->to_level) : null;

        switch ($a->action) {
            case Activity::MOVED:
                $this->assertStandsAt($container, $to);
                $this->assertFromSlotExists($from);
                $this->moves->assertCanTake($container);
                $this->moves->assertCanPlace($from, $container->id);

                return $this->moves->apply($container, Activity::UNDONE, $actor, slot: $from, undoesId: $a->id);

            case Activity::PLACED:
                $this->assertStandsAt($container, $to);
                $this->moves->assertCanTake($container);

                return $this->moves->apply($container, Activity::UNDONE, $actor,
                    status: Container::PENDING, slot: null, undoesId: $a->id);

            case Activity::DELIVERED:
                if ($container->status !== Container::DELIVERED) {
                    throw new MoveRejected('Containeren er ikke længere udleveret.');
                }
                $this->assertFromSlotExists($from);
                $this->moves->assertCanPlace($from);

                return $this->moves->apply($container, Activity::UNDONE, $actor,
                    status: Container::STORED, slot: $from, undoesId: $a->id);

            case Activity::RETURNED:
                $this->assertStandsAt($container, $to);
                $this->moves->assertCanTake($container);

                return $this->moves->apply($container, Activity::UNDONE, $actor,
                    status: Container::DELIVERED, slot: null, customerId: $a->from_customer_id, undoesId: $a->id);

            case Activity::EDITED:
                return $this->moves->apply($container, Activity::UNDONE, $actor,
                    customerId: $a->from_customer_id, name: $a->from_name, undoesId: $a->id);

            case Activity::TRANSFERRED:
                // Containeren er aldrig kørt: den kommer tilbage på sin gamle plads, og reservationen frigives.
                if (! $container->isInTransit() || ! $to?->equals($container->location())) {
                    throw new MoveRejected('Containeren er ikke længere i transit.');
                }
                $this->assertFromSlotExists($from);
                $this->moves->assertCanPlace($from, $container->id);

                return $this->moves->apply($container, Activity::UNDONE, $actor,
                    status: Container::STORED, slot: $from, undoesId: $a->id);

            case Activity::ARRIVED:
                // Tilbage til transit. Det kræver, at intet er sat oven på eller foran den imens.
                $this->assertStandsAt($container, $to);
                $this->moves->assertCanTake($container);

                return $this->moves->apply($container, Activity::UNDONE, $actor,
                    status: Container::TRANSIT, undoesId: $a->id);
        }

        throw new MoveRejected('Denne handling kan ikke fortrydes.');
    }

    private function assertStandsAt(Container $container, ?Slot $slot): void
    {
        if (! $container->isStored() || ! $slot?->equals($container->slot())) {
            throw new MoveRejected('Containeren står ikke længere, hvor handlingen satte den.');
        }
    }

    private function assertFromSlotExists(?Slot $slot): void
    {
        if ($slot === null) {
            throw new MoveRejected('Den oprindelige plads findes ikke længere.');
        }
    }
}
