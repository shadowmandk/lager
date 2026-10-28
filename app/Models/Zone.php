<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $fillable = ['warehouse_id', 'name', 'is_buffer', 'sort'];

    protected function casts(): array
    {
        return ['is_buffer' => 'boolean'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(StorageRow::class)->orderBy('sort')->orderBy('id');
    }
}
