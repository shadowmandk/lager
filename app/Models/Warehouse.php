<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Warehouse extends Model
{
    protected $fillable = ['name', 'sort'];

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class)->orderBy('sort')->orderBy('id');
    }

    public function rows(): HasManyThrough
    {
        return $this->hasManyThrough(StorageRow::class, Zone::class);
    }
}
