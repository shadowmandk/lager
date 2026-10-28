<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Fast farve pr. kunde, udledt af kundenummeret, så samme kunde altid har samme farve.
 * Farverne er valgt med mørk tekst og høj kontrast, så de kan læses i sollys.
 */
final class CustomerPalette
{
    /** @var list<array{side: string, top: string, end: string, stroke: string, text: string}> */
    public const COLORS = [
        ['side' => '#5B9BD5', 'top' => '#8DB9E3', 'end' => '#3F7DB8', 'stroke' => '#1F4E79', 'text' => '#0B2540'],
        ['side' => '#E07A3F', 'top' => '#EDA27A', 'end' => '#BF5E25', 'stroke' => '#7A3510', 'text' => '#3D1A06'],
        ['side' => '#5FAE5A', 'top' => '#8CCB88', 'end' => '#438F3F', 'stroke' => '#25561F', 'text' => '#0F2A0C'],
        ['side' => '#C9A227', 'top' => '#E0C25E', 'end' => '#A5831A', 'stroke' => '#5E4A0A', 'text' => '#2E2403'],
        ['side' => '#9B6FC7', 'top' => '#BD9DDD', 'end' => '#7C51A9', 'stroke' => '#452A66', 'text' => '#221333'],
        ['side' => '#D9534F', 'top' => '#E88A87', 'end' => '#B53A36', 'stroke' => '#6E1C19', 'text' => '#380B09'],
        ['side' => '#3BAFA8', 'top' => '#78CCC7', 'end' => '#258F89', 'stroke' => '#12524E', 'text' => '#062A28'],
        ['side' => '#D673A6', 'top' => '#E6A2C5', 'end' => '#B65286', 'stroke' => '#6B2A4B', 'text' => '#361224'],
        ['side' => '#8C9A3A', 'top' => '#B1BE6A', 'end' => '#6F7C24', 'stroke' => '#3E4610', 'text' => '#1E2305'],
        ['side' => '#4F6FB0', 'top' => '#8297CB', 'end' => '#3A5592', 'stroke' => '#1D2F57', 'text' => '#0C1730'],
        ['side' => '#B5835A', 'top' => '#CFA885', 'end' => '#946440', 'stroke' => '#55361F', 'text' => '#2B190C'],
        ['side' => '#5C8A8A', 'top' => '#8DB0B0', 'end' => '#436E6E', 'stroke' => '#223F3F', 'text' => '#0E2020'],
    ];

    /** Neutral grå til containere uden kunde. */
    public const NONE = ['side' => '#A7ABB0', 'top' => '#C8CBCF', 'end' => '#878B91', 'stroke' => '#4A4E54', 'text' => '#1E2125'];

    /** @return array{side: string, top: string, end: string, stroke: string, text: string} */
    public static function for(?Customer $customer): array
    {
        if ($customer === null) {
            return self::NONE;
        }

        return self::COLORS[crc32($customer->number) % count(self::COLORS)];
    }
}
