<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalance extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $balance)
    {
        parent::__construct('Not enough tokens');
    }
}
