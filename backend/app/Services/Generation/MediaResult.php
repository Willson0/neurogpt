<?php

namespace App\Services\Generation;

final class MediaResult
{
    public function __construct(public readonly string $bytes, public readonly string $extension) {}
}
