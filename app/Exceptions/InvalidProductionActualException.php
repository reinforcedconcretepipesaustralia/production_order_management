<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class InvalidProductionActualException extends Exception implements ShouldntReport {}
