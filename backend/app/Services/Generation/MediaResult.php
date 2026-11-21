<?php

namespace App\Services\Generation;

final class MediaResult
{
    /** @param list<array{url:string, title:string, id?:string, duration?:float, lyrics?:string, artwork?:string}> $tracks */
    public function __construct(
        public readonly string $bytes,
        public readonly string $extension,
        public readonly array $music = [],
        public readonly array $tracks = [],
    ) {}
}
