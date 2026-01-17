<?php

namespace App\Enums;

use BackedEnum;

enum eScriptoriumStatusEnum: string
{
    case Pending = 'pending';
    case Importing = 'importing';
    case Segmenting = 'segmenting';
    case Transcribing = 'transcribing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'PENDING',
            self::Importing => 'IMPORTING',
            self::Segmenting => 'SEGMENTING',
            self::Transcribing => 'TRANSCRIBING',
            self::Completed => 'COMPLETED',
            self::Failed => 'FAILED',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Importing => 'info',
            self::Segmenting => 'primary',
            self::Transcribing => 'purple',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public static function values(): array
    {
        return array_map(fn (BackedEnum $case) => $case->value, self::cases());
    }
}
