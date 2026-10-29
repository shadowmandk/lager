<?php

namespace App\Services\Microbizz;

use App\Models\Container;
use App\Models\Setting;

/**
 * Hvilket udstyr i Microbizz er containere, og hvilke felter bruges. Gemmes under Opsætning.
 */
final class MicrobizzSettings
{
    public const FILTER_FIELD = 'microbizz.filter_field';

    public const FILTER_VALUE = 'microbizz.filter_value';

    public const NUMBER_FIELD = 'microbizz.number_field';

    public const NAME_FIELD = 'microbizz.name_field';

    public const NEW_STATUS = 'microbizz.new_status';

    public const CUSTOMERS_SINCE = 'microbizz.customers_since';

    public const TOOLS_SINCE = 'microbizz.tools_since';

    /** Felter på udstyret, som kan afgøre, om det er en container. "all" betyder alt udstyr. */
    public const FILTER_FIELDS = [
        'group' => 'Gruppe',
        'class' => 'Klasse',
        'groupid' => 'Gruppe-id',
        'all' => 'Alt udstyr er containere',
    ];

    public const NUMBER_FIELDS = [
        'number' => 'Nummer',
        'code' => 'Kode',
        'barcode' => 'Stregkode',
        'serialnumber' => 'Serienummer',
        'name' => 'Navn',
    ];

    public const NAME_FIELDS = [
        'name' => 'Navn',
        'model' => 'Model',
        'make' => 'Mærke',
        'secondary_text' => 'Søgetekst',
        'none' => 'Intet navn',
    ];

    public const NEW_STATUSES = [
        Container::PENDING => 'Venter på plads',
        Container::DELIVERED => 'Udleveret til kunde',
    ];

    public static function filterField(): string
    {
        return self::pick(self::FILTER_FIELD, self::FILTER_FIELDS, 'group');
    }

    public static function filterValue(): string
    {
        return trim((string) Setting::get(self::FILTER_VALUE));
    }

    /** Er der valgt, hvilket udstyr der er containere? Ellers hentes intet udstyr. */
    public static function hasFilter(): bool
    {
        return self::filterField() === 'all' || self::filterValue() !== '';
    }

    /** @param  array<string, mixed>  $tool */
    public static function isContainer(array $tool): bool
    {
        if (! self::hasFilter()) {
            return false;
        }

        if (self::filterField() === 'all') {
            return true;
        }

        return mb_strtolower(trim((string) ($tool[self::filterField()] ?? ''))) === mb_strtolower(self::filterValue());
    }

    public static function numberField(): string
    {
        return self::pick(self::NUMBER_FIELD, self::NUMBER_FIELDS, 'number');
    }

    public static function nameField(): string
    {
        return self::pick(self::NAME_FIELD, self::NAME_FIELDS, 'name');
    }

    public static function newStatus(): string
    {
        return self::pick(self::NEW_STATUS, self::NEW_STATUSES, Container::PENDING);
    }

    /** @param  array<string, string>  $allowed */
    private static function pick(string $key, array $allowed, string $default): string
    {
        $value = Setting::get($key) ?? $default;

        return array_key_exists($value, $allowed) ? $value : $default;
    }
}
