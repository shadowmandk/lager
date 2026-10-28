<?php

namespace App\Livewire;

use App\Models\Operator;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Vælg bruger')]
class OperatorPicker extends Component
{
    public function mount(): mixed
    {
        $chosen = Operator::query()->where('active', true)->find(session('operator_id'));

        if ($chosen && ! request()->boolean('skift')) {
            return $this->redirectRoute('board');
        }

        return null;
    }

    public function choose(int $operatorId): mixed
    {
        $operator = Operator::query()->where('active', true)->findOrFail($operatorId);

        session()->put('operator_id', $operator->id);

        return $this->redirectRoute('board');
    }

    public function render()
    {
        return view('livewire.operator-picker', [
            'operators' => Operator::query()->active()->get(),
        ]);
    }
}
