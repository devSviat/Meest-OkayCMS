<?php

namespace Okay\Modules\Sviat\Meest\Integration;

/** `group` — ключ групи статусів Meest (created, cancelled, in_transit, arrived, storage, problem, returning, received, returned). */
final readonly class MeestParcel
{
    public function __construct(
        public int $orderId,
        public string $barcode,
        public ?string $group,
        public ?string $statusAt,
        public ?string $arrivedAt,
    ) {
    }
}
