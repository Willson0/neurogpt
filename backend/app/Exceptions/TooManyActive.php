<?php

namespace App\Exceptions;

use RuntimeException;

class TooManyActive extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct('Too many generations in progress');
    }
}
