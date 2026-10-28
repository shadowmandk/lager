<?php

namespace App\Livewire;

use App\Models\Activity;
use App\Models\Operator;
use App\Services\Actor;
use App\Services\MoveRejected;
use App\Services\UndoService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Log')]
class ActivityLog extends Component
{
    use WithPagination;

    #[Url(as: 'bruger')]
    public ?int $operatorFilter = null;

    #[Url(as: 'container', except: '')]
    public string $containerFilter = '';

    public ?int $confirmUndoId = null;

    public ?string $notice = null;

    public string $noticeType = 'ok';

    public int $noticeId = 0;

    public function updated(string $property): void
    {
        if (in_array($property, ['operatorFilter', 'containerFilter'], true)) {
            $this->resetPage();
        }
    }

    public function askUndo(int $id): void
    {
        $this->confirmUndoId = $id;
    }

    public function cancelUndo(): void
    {
        $this->confirmUndoId = null;
    }

    public function undo(): void
    {
        $activity = Activity::query()->find($this->confirmUndoId);
        $this->confirmUndoId = null;

        if ($activity === null) {
            return;
        }

        try {
            $undo = app(UndoService::class)->undo($activity, Actor::operator((int) session('operator_id')));
            $number = $activity->container?->number;
            $this->flash("{$activity->label()} af {$number} er fortrudt. Containeren står nu: {$undo->to_label}.");
        } catch (MoveRejected $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    public function render()
    {
        $undo = app(UndoService::class);
        $window = $undo->window();
        $candidate = $window->first(fn (Activity $a) => ! $a->isUndone());

        $entries = Activity::query()
            ->with('container.customer', 'operator', 'user', 'fromCustomer', 'toCustomer')
            ->when($this->operatorFilter, fn ($q) => $q->where('operator_id', $this->operatorFilter))
            ->when(trim($this->containerFilter) !== '', fn ($q) => $q->whereHas('container',
                fn ($c) => $c->withTrashed()->where('number', 'like', '%'.trim($this->containerFilter).'%')))
            ->orderByDesc('id')
            ->paginate(40);

        return view('livewire.activity-log', [
            'entries' => $entries,
            'operators' => Operator::query()->orderBy('name')->get(),
            'windowIds' => $window->pluck('id')->flip()->all(),
            'candidateId' => $candidate?->id,
            'confirmEntry' => $this->confirmUndoId ? Activity::query()->with('container')->find($this->confirmUndoId) : null,
        ]);
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $this->notice = $message;
        $this->noticeType = $type;
        $this->noticeId++;
    }
}
