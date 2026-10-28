<?php

namespace App\Models;

use App\Support\CustomerPalette;
use App\Support\Slot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Container extends Model
{
    use SoftDeletes;

    /** Står på en plads på et lager. */
    public const STORED = 'stored';

    /** Udleveret til kunde. */
    public const DELIVERED = 'delivered';

    /** Oprettet, men endnu ikke placeret. */
    public const PENDING = 'pending';

    public const STATUS_LABELS = [
        self::STORED => 'På lager',
        self::DELIVERED => 'Udleveret til kunde',
        self::PENDING => 'Venter på plads',
    ];

    protected $fillable = ['number', 'name', 'customer_id', 'status', 'storage_row_id', 'depth', 'level'];

    protected function casts(): array
    {
        return [
            'storage_row_id' => 'integer',
            'depth' => 'integer',
            'level' => 'integer',
            'customer_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(StorageRow::class, 'storage_row_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('id');
    }

    public function isStored(): bool
    {
        return $this->status === self::STORED;
    }

    public function slot(): ?Slot
    {
        if (! $this->isStored()) {
            return null;
        }

        return new Slot($this->storage_row_id, $this->depth, $this->level);
    }

    /** @return array{side: string, top: string, end: string, stroke: string, text: string} */
    public function color(): array
    {
        return CustomerPalette::for($this->customer);
    }

    /** Søger i containernummer, containernavn, kundenavn og kundenummer. */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('number', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhereHas('customer', fn (Builder $c) => $c
                    ->where('name', 'like', $like)
                    ->orWhere('number', 'like', $like));
        });
    }
}
