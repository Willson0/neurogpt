<?php

namespace App\Exceptions;

use RuntimeException;

class ModelLocked extends RuntimeException
{
    public function __construct(public readonly string $plan)
    {
        parent::__construct('Model requires plan '.$plan);
    }
}
