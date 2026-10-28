<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const MINUTES_PER_MOVE = 'minutes_per_move';

    public const DEFAULTS = [
        self::MINUTES_PER_MOVE => '4',
    ];

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key): ?string
    {
        return static::query()->find($key)?->value ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function minutesPerMove(): int
    {
        return max(0, (int) static::get(self::MINUTES_PER_MOVE));
    }
}
