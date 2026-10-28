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

    /** På vej til det andet lager. Bufferpladsen, den skal stå på, er reserveret i placeringsfelterne. */
    public const TRANSIT = 'transit';

    /** Statusser, hvor containeren optager en plads (stående eller reserveret). */
    public const ON_SLOT = [self::STORED, self::TRANSIT];

    public const STATUS_LABELS = [
        self::STORED => 'På lager',
        self::DELIVERED => 'Udleveret til kunde',
        self::PENDING => 'Venter på plads',
        self::TRANSIT => 'I transit',
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

    /** Containernumre gemmes med store bogstaver og enkelte mellemrum, så "msku 1" og "MSKU 1" er samme container. */
    public static function normalizeNumber(string $number): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($number)));
    }

    public function isInTransit(): bool
    {
        return $this->status === self::TRANSIT;
    }

    public function occupiesSlot(): bool
    {
        return in_array($this->status, self::ON_SLOT, true);
    }

    /** Pladsen, containeren står på. Kun for containere på lager. */
    public function slot(): ?Slot
    {
        if (! $this->isStored()) {
            return null;
        }

        return new Slot($this->storage_row_id, $this->depth, $this->level);
    }

    /** Pladsen, containeren står på eller har reserveret, hvis den er i transit. */
    public function location(): ?Slot
    {
        if (! $this->occupiesSlot()) {
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
