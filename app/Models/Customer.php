<?php

namespace App\Models;

use App\Support\CustomerPalette;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['number', 'name'];

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
