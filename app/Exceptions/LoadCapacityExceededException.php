<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class LoadCapacityExceededException extends Exception implements ShouldntReport {}
