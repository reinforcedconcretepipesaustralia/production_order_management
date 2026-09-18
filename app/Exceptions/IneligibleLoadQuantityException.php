<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class IneligibleLoadQuantityException extends Exception implements ShouldntReport {}
