<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class InvalidOrderAllocationException extends Exception implements ShouldntReport {}
