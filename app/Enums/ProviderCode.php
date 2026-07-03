<?php

namespace App\Enums;

enum ProviderCode: string
{
    case Yas = 'YAS';
    case Vodacom = 'VODACOM';
    case Halotel = 'HALOTEL';
    case Airtel = 'AIRTEL';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
