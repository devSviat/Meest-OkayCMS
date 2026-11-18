<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/**
 * Що показати менеджеру біля форми чи картки посилки.
 *
 * `text` — мовний ключ `sviat__meest__…` або текст Meest як є (шаблон його екранує); `field` — поле форми
 * (ParcelInput) для позначки або null; `advice` — мовний ключ поради.
 */
final readonly class ParcelIssue
{
    public function __construct(
        public ?string $field,
        public string $text,
        public ?string $advice = null,
    ) {
    }
}
