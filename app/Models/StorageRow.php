<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * En række containere, der kun kan tilgås fra enden (dybde 1).
 * Pladserne er ikke rækker i databasen; de er dybde × højde.
 */
class StorageRow extends Model
{
    protected $fillable = ['zone_id', 'name', 'depth', 'height', 'sort'];

    protected function casts(): array
    {
        return ['depth' => 'integer', 'height' => 'integer'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class)->whereIn('status', Container::ON_SLOT);
    }

    public function capacity(): int
    {
        return $this->depth * $this->height;
    }

    /**
     * Pladsens navn: række, plads talt fra indgangen, højde talt nedefra. "A 3 2".
     * Rækker med kun én plads (bufferstakke) har ikke pladstallet med: "Buffer 2 1".
     */
    public function slotName(int $depth, int $level): string
    {
        return $this->depth === 1 ? "{$this->name} {$level}" : "{$this->name} {$depth} {$level}";
    }

    /** "Lager 1 · A" */
    public function label(): string
    {
        return $this->zone->warehouse->name.' · '.$this->name;
    }
}
