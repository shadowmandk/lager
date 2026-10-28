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
        return $this->hasMany(Container::class)->where('status', Container::STORED);
    }

    public function capacity(): int
    {
        return $this->depth * $this->height;
    }

    /** "Lager 1 · A" */
    public function label(): string
    {
        return $this->zone->warehouse->name.' · '.$this->name;
    }
}
