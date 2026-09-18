<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

class CalculationInputException extends Exception implements ShouldntReport
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            message: 'The calculation inputs conflict with domain requirements.',
            previous: $previous,
        );
    }
}
