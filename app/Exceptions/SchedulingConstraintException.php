<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class SchedulingConstraintException extends Exception implements ShouldntReport
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
