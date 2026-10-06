<?php

namespace App\Enums;

enum DeliveryConfirmationStage: string
{
    case Weeks34 = 'weeks_3_4';
    case Weeks12 = 'weeks_1_2';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Weeks34 => '4 weeks',
            self::Weeks12 => '2 weeks',
            self::Overdue => 'overdue',
        };
    }

    public function subjectPhrase(): string
    {
        return match ($this) {
            self::Weeks34 => 'coming up in about 4 weeks',
            self::Weeks12 => 'coming up within 2 weeks',
            self::Overdue => 'overdue',
        };
    }
}
