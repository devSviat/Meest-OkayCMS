<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/** Відповідь на створення, пошук, прив'язку чи видалення посилки від менеджера. */
final readonly class ParcelOutcome
{
    /**
     * @param list<ParcelIssue> $issues
     * @param list<string> $notices мовні ключі того, що менеджеру варто знати
     * @param list<array{parcel_id: string, barcode: ?string, date: ?string, receiver: ?string, amount: ?float}> $candidates
     *        посилки, з яких менеджер вибирає, коли пошук знайшов кілька
     * @param ?string $value показується поруч із відмовою (штрихкод, id замовлення)
     */
    public function __construct(
        public bool $ok,
        public ?string $barcode = null,
        public array $issues = [],
        public array $notices = [],
        public array $candidates = [],
        public ?string $value = null,
    ) {
    }

    /** @param list<ParcelIssue> $issues */
    public static function failed(array $issues, ?string $value = null): self
    {
        return new self(false, null, $issues, [], [], $value);
    }

    public static function refused(string $key, ?string $value = null): self
    {
        return self::failed([new ParcelIssue(null, $key)], $value);
    }
}
