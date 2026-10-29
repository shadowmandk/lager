<?php

namespace App\Models;

use App\Support\CustomerPalette;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['number', 'name', 'microbizz_id', 'microbizz_inactive', 'color'];

    protected function casts(): array
    {
        return ['microbizz_id' => 'integer', 'microbizz_inactive' => 'boolean', 'color' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            $customer->color ??= CustomerPalette::leastUsed();
        });
    }

    /** Kommer fra Microbizz, som bestemmer nummer og navn. */
    public function isFromMicrobizz(): bool
    {
        return $this->microbizz_id !== null;
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    /** @return array{side: string, top: string, end: string, stroke: string, text: string} */
    public function color(): array
    {
        return CustomerPalette::for($this);
    }

    public function label(): string
    {
        return "{$this->name} ({$this->number})";
    }
}
