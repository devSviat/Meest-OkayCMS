<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Psr\Log\LoggerInterface;

/**
 * Створює посилки для вибраних у списку замовлень, одну за одною в цьому запиті, кожну з першою
 * чернеткою замовлення, як форма без змін менеджера.
 */
final class MassParcels
{
    /** Кожне замовлення — один POST parcel до 20 с; сторінка чекає на всі. */
    public const int LIMIT = 20;
    /**
     * Після цього нове замовлення не починається; решта лишається на наступний запуск. POST parcel триває
     * до ~20 с, тож замовлення, почате на 45-й секунді, може скінчитися на ~65-й, після 60 с nginx: браузер
     * тоді бачить 504, а PHP з ignore_user_abort доробляє, і звіт однаково лягає в сесію.
     */
    public const float BUDGET_SECONDS = 45.0;
    private const string KEY = 'sviat__meest__';
    /** Відмови через ключ кабінету: решта пакета отримала б ту саму, тож вона не резервується й не йде до Meest. */
    private const array STOPS_BATCH = [self::KEY . 'parcel_api_bad_token', self::KEY . 'parcel_api_not_configured'];

    private readonly \Closure $clock;

    /** @param ?\Closure(): float $clock секунди */
    public function __construct(
        private readonly ParcelService $service,
        private readonly ParcelRows $rows,
        private readonly MassOrders $orders,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** @param array<mixed> $rawIds надісланий `check[]` */
    public function run(array $rawIds): MassReport
    {
        $ids = self::ids($rawIds);
        $batch = array_slice($ids, 0, self::LIMIT);
        $untouched = count($ids) - count($batch);
        $start = ($this->clock)();
        $config = null;
        $rows = [];
        foreach ($batch as $index => $orderId) {
            if (($this->clock)() - $start > self::BUDGET_SECONDS) {
                $untouched += count($batch) - $index;
                break;
            }
            try {
                $config ??= $this->orders->config();
                $rows[] = $row = ['orderId' => $orderId] + $this->one($orderId, $config);
                if ($row['kind'] === 'failed' && in_array($row['key'], self::STOPS_BATCH, true)) {
                    foreach (array_slice($batch, $index + 1) as $rest) {
                        $rows[] = ['orderId' => $rest, 'kind' => 'skipped', 'key' => $row['key'], 'value' => null];
                    }
                    break;
                }
            } catch (\Throwable $e) {
                // Одне зламане замовлення не має позбавити звіту решту. У лог — лише клас: текст винятку бази
                // чи транспорту може нести ПІБ або телефон одержувача.
                $this->logger->error("Meest mass parcel for order {$orderId} failed: " . $e::class);
                $rows[] = ['orderId' => $orderId, 'kind' => 'failed', 'key' => self::KEY . 'mass_failed_generic', 'value' => null];
            }
        }

        return new MassReport($rows, $untouched);
    }

    /**
     * @param array<mixed> $raw надісланий `check[]`
     * @return list<int> додатні, унікальні, у надісланому порядку
     */
    public static function ids(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if ((is_string($value) || is_int($value)) && preg_match('/^\d{1,10}$/', (string)$value) && (int)$value > 0) {
                $ids[(int)$value] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return array{kind: 'created'|'skipped'|'failed', key: ?string, value: ?string} */
    private function one(int $orderId, ParcelConfig $config): array
    {
        $skip = static fn(string $key, ?string $value = null): array => ['kind' => 'skipped', 'key' => $key, 'value' => $value];
        if (!$this->orders->isOurs($orderId)) {
            return $skip(self::KEY . 'parcel_err_not_own');
        }
        $existing = $this->rows->forOrder($orderId);
        if ($existing !== null) {
            // Резерв чи невідомий результат розбирають на сторінці замовлення: пошук або прив'язка, не повтор.
            return PendingParcel::is($existing)
                ? $skip(self::KEY . 'mass_unsettled')
                : $skip(self::KEY . 'mass_has_parcel', is_string($existing->barcode ?? null) ? $existing->barcode : null);
        }
        $draft = $this->orders->draft($orderId);
        if (is_string($draft)) {
            return $skip($draft);
        }
        $errors = $draft->errors();
        if ($errors !== []) {
            return $skip((string)reset($errors));
        }

        $outcome = $this->service->create($orderId, $draft, $config);
        if ($outcome->ok) {
            return ['kind' => 'created', 'key' => null, 'value' => $outcome->barcode];
        }
        $first = $outcome->issues[0] ?? null;
        if ($first === null) {
            return ['kind' => 'failed', 'key' => self::KEY . 'mass_failed_generic', 'value' => $outcome->value];
        }
        // Текст Meest іде як значення під загальною відмовою: ключем може бути лише мовний ключ модуля.
        $own = str_starts_with($first->text, self::KEY);

        return [
            'kind' => 'failed',
            'key' => $own ? $first->text : self::KEY . 'parcel_api_rejected',
            'value' => $own ? $outcome->value : $first->text,
        ];
    }
}
