<?php

namespace App\Livewire;

use App\Support\DeviceLock;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Låst')]
class UnlockScreen extends Component
{
    public ?string $error = null;

    /** Sekunder, før man må prøve igen efter for mange forkerte forsøg. */
    public int $wait = 0;

    public int $attemptId = 0;

    public function mount(): mixed
    {
        return DeviceLock::isUnlocked() ? $this->redirectRoute('operator.pick') : null;
    }

    public function unlock(string $code): mixed
    {
        $this->attemptId++;

        if (! DeviceLock::hasCode()) {
            $this->error = 'Der er ikke valgt nogen kode endnu. Kontoret vælger den under Opsætning.';

            return null;
        }

        $result = DeviceLock::attempt($code, (string) request()->ip());

        if ($result['ok']) {
            return $this->redirectRoute('operator.pick');
        }

        $this->wait = $result['wait'];
        $this->error = $this->wait > 0
            ? 'For mange forkerte forsøg. Prøv igen om '.$this->waitText($this->wait).'.'
            : 'Forkert kode. Prøv igen.';

        return null;
    }

    public function render()
    {
        return view('livewire.unlock-screen', [
            'hasCode' => DeviceLock::hasCode(),
            'length' => DeviceLock::CODE_LENGTH,
        ]);
    }

    private function waitText(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return $minutes <= 1 ? 'et minut' : "{$minutes} minutter";
    }
}
