<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Én hentning fra Microbizz: hvem startede den, hvad blev ændret, og gik det godt. */
class MicrobizzSync extends Model
{
    public const TRIGGERS = [
        'schedule' => 'Automatisk',
        'admin' => 'Opsætning',
        'board' => 'Tavlen',
    ];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'running',
        'customers_created' => 0,
        'customers_updated' => 0,
        'containers_created' => 0,
        'containers_updated' => 0,
        'containers_removed' => 0,
    ];

    protected function casts(): array
    {
        return [
            'notes' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger] ?? $this->trigger;
    }

    /** "2 nye containere, 1 opdateret" eller "Ingen ændringer". */
    public function summary(): string
    {
        $parts = array_filter([
            $this->containers_created ? $this->containers_created.' '.($this->containers_created === 1 ? 'ny container' : 'nye containere') : null,
            $this->containers_updated ? $this->containers_updated.' '.($this->containers_updated === 1 ? 'container opdateret' : 'containere opdateret') : null,
            $this->containers_removed ? $this->containers_removed.' fjernet i Microbizz' : null,
            $this->customers_created ? $this->customers_created.' '.($this->customers_created === 1 ? 'ny kunde' : 'nye kunder') : null,
            $this->customers_updated ? $this->customers_updated.' '.($this->customers_updated === 1 ? 'kunde opdateret' : 'kunder opdateret') : null,
        ]);

        return $parts === [] ? 'Ingen ændringer' : implode(', ', $parts);
    }
}
