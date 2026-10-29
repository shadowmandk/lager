<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    public const CREATED = 'created';

    public const PLACED = 'placed';

    public const MOVED = 'moved';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const EDITED = 'edited';

    public const UNDONE = 'undone';

    /** Sendt i transit til bufferen på et andet lager. */
    public const TRANSFERRED = 'transferred';

    /** Meldt sat i buffer efter transit. */
    public const ARRIVED = 'arrived';

    /** Nummer, navn eller kunde opdateret fra Microbizz. Kan ikke fortrydes. */
    public const SYNCED = 'synced';

    /** Handlinger, der kan fortrydes. */
    public const UNDOABLE = [self::PLACED, self::MOVED, self::DELIVERED, self::RETURNED, self::EDITED, self::TRANSFERRED, self::ARRIVED];

    public const LABELS = [
        self::CREATED => 'Oprettet',
        self::PLACED => 'Placeret',
        self::MOVED => 'Flyttet',
        self::DELIVERED => 'Udleveret',
        self::RETURNED => 'Hjemkommet',
        self::EDITED => 'Rettet',
        self::UNDONE => 'Fortrudt',
        self::TRANSFERRED => 'Sendt til andet lager',
        self::ARRIVED => 'Sat i buffer',
        self::SYNCED => 'Opdateret fra Microbizz',
    ];

    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class)->withTrashed();
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'from_customer_id');
    }

    public function toCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'to_customer_id');
    }

    public function undoes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'undoes_id');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'undone_by_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }

    public function actorName(): string
    {
        if ($this->source === 'microbizz') {
            return 'Microbizz';
        }

        return $this->operator?->name ?? ($this->user ? $this->user->name.' (admin)' : 'Ukendt');
    }

    public function isUndone(): bool
    {
        return $this->undone_by_id !== null;
    }
}
