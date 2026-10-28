<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Medarbejder, der bruger tavlen. Ingen adgangskode; bruges kun til sporbarhed i loggen.
 */
class Operator extends Model
{
    protected $fillable = ['name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true)->orderBy('name');
    }
}
