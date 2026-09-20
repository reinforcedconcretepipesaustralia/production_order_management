<?php

namespace App\Services\Sync;

use RuntimeException;

class UnknownTenciaEntityException extends RuntimeException
{
    public function __construct(string $entityCode)
    {
        parent::__construct(sprintf(
            'No Tencia database is mapped to entity [%s]. Add it to TenciaSource, or clear the '
            .'entity code on the plants that use it.',
            $entityCode,
        ));
    }
}
