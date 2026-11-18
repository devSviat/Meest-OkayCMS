<?php

namespace Okay\Modules\Sviat\Meest\Integration;

final readonly class MeestAddress
{
    public function __construct(public string $city, public string $line, public string $number)
    {
    }
}
