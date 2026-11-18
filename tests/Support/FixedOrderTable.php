<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Modules\Sviat\Meest\Orders\OrderTable;

final class FixedOrderTable implements OrderTable
{
    public int $checks = 0;

    public function __construct(private readonly bool $exists = true)
    {
    }

    public function exists(): bool
    {
        $this->checks++;

        return $this->exists;
    }
}
