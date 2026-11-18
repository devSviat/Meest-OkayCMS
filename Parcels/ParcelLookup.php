<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Psr\Log\LoggerInterface;

/**
 * Шукає в Meest посилку, створення якої обірвалося: `parcelsList/{dd.MM.yyyy}` за день резерву й наступний.
 * Елемент списку має лише parcelID, parcelNumber, штрихкод і реєстр, тож збіг — це точне `parcelNumber` рядка
 * (`{order_id}-{id}`); статус «видалено» перевіряє `parcelStatus/{parcelID}`, і лише для збігів.
 * У лог іде лише кількість знайденого, без даних одержувача.
 */
final class ParcelLookup
{
    private const string MEEST_ZONE = 'Europe/Kyiv';
    private const int STATUS_DELETED = 3;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly MeestClient $client,
        private readonly ParcelRows $rows,
        private readonly CredentialsProvider $credentials,
        private readonly ParcelIssues $issues,
        private readonly LoggerInterface $logger,
        private readonly \Closure $now,
    ) {
    }

    public function find(int $orderId): ParcelOutcome
    {
        return $this->resolve($orderId, null);
    }

    /** Перечитує Meest і прив'язує лише того кандидата, що й тепер проходить усі фільтри. */
    public function choose(int $orderId, string $parcelId): ParcelOutcome
    {
        return $this->resolve($orderId, strtolower($parcelId));
    }

    private function resolve(int $orderId, ?string $chosen): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_none');
        }
        if (!PendingParcel::is($row)) {
            return ParcelOutcome::refused('sviat__meest__parcel_exists', is_string($row->barcode ?? null) ? $row->barcode : null);
        }
        if (!PendingParcel::needsCheck($row, ($this->now)())) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_pending');
        }
        $number = is_string($row->parcel_number ?? null) ? $row->parcel_number : '';
        if ($number === '') {
            return ParcelOutcome::refused('sviat__meest__parcel_err_lookup_none');
        }

        try {
            $found = $this->matches($orderId, $row, $number);
        } catch (MeestApiException $e) {
            return ParcelOutcome::failed($this->issues->explain($e));
        }
        if ($chosen !== null) {
            $found = array_values(array_filter($found, static fn(array $item): bool => strtolower($item['parcelID']) === $chosen));
            if ($found === []) {
                return ParcelOutcome::refused('sviat__meest__parcel_err_lookup_gone');
            }
        }
        $this->logger->info("Meest lookup for order {$orderId}: " . count($found) . ' match(es)');

        return match (count($found)) {
            0 => ParcelOutcome::refused('sviat__meest__parcel_err_lookup_none'),
            1 => $this->bind($orderId, $found[0]),
            // Яка з посилок справжня, знає менеджер: автоматичний вибір міг би прив'язати дубль.
            default => new ParcelOutcome(false, null, [], ['sviat__meest__parcel_lookup_many'], array_map(
                static fn(array $item): array => ['parcel_id' => $item['parcelID'], 'barcode' => $item['barcode'], 'date' => null, 'receiver' => null, 'amount' => null],
                $found,
            )),
        };
    }

    /**
     * @param array{parcelID: string, barcode: string, raw: array<mixed>} $item
     */
    private function bind(int $orderId, array $item): ParcelOutcome
    {
        $contract = $this->credentials->get()->contractId;
        $saved = $this->rows->complete($orderId, [
            'parcel_id' => $item['parcelID'],
            'barcode' => $item['barcode'],
            'contract_id' => $contract !== '' ? $contract : null,
            'details' => ParcelDetails::encode(ParcelDetails::fromApi($item['raw'])),
        ]);
        if (!$saved) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_not_saved', $item['barcode']);
        }
        $this->logger->info("Meest parcel for order {$orderId} found by lookup");

        return new ParcelOutcome(true, $item['barcode']);
    }

    /**
     * @return list<array{parcelID: string, barcode: string, raw: array<mixed>}>
     * @throws MeestApiException
     */
    private function matches(int $orderId, object $row, string $number): array
    {
        $found = [];
        foreach ($this->days($row) as $day) {
            foreach ($this->listDay($day) as $item) {
                $parcelId = ParcelService::uuid($item['parcelID'] ?? null);
                if ($parcelId === null || ($item['parcelNumber'] ?? null) !== $number || isset($found[strtolower($parcelId)])) {
                    continue;
                }
                // `вarCode` з кириличною «в» — так поле названо в схемі.
                $raw = $item['вarCode'] ?? $item['barCode'] ?? null;
                $barcode = is_string($raw) ? Barcode::parse($raw) : null;
                if ($barcode === null || $this->boundElsewhere($barcode, $orderId, $parcelId)) {
                    continue;
                }
                $found[strtolower($parcelId)] = ['parcelID' => $parcelId, 'barcode' => $barcode, 'raw' => $item];
            }
        }

        // parcelStatus лише для точних збігів: список не каже, чи посилку видалено.
        return array_values(array_filter($found, fn(array $item): bool => !$this->deleted($item['parcelID'])));
    }

    /**
     * 404 на день читається як порожній день, а не збій: схема не каже, що Meest відповідає на день без посилок.
     *
     * @return list<array<mixed>>
     * @throws MeestApiException
     */
    private function listDay(string $day): array
    {
        try {
            return MeestClient::rows($this->client->call('GET', 'parcelsList/' . $day));
        } catch (MeestApiException $e) {
            if ($e->kind === ApiErrorKind::NotFound) {
                return [];
            }
            throw $e;
        }
    }

    private function boundElsewhere(string $barcode, int $orderId, string $parcelId): bool
    {
        $byId = $this->rows->orderOfParcelId($parcelId);
        if ($byId !== null && $byId !== $orderId) {
            return true;
        }
        $owner = $this->rows->orderOfBarcode($barcode);

        return $owner !== null && $owner !== $orderId;
    }

    /** @throws MeestApiException */
    private function deleted(string $parcelId): bool
    {
        foreach (MeestClient::rows($this->client->plain('parcelStatus/' . rawurlencode($parcelId))) as $status) {
            if (is_numeric($status['statusCode'] ?? null) && (int)$status['statusCode'] === self::STATUS_DELETED) {
                return true;
            }
        }

        return false;
    }

    /**
     * День резерву й наступний за Києвом: Meest групує за київською датою, а рядок зберігає пояс застосунку,
     * тож о 23:50 UTC вже інший київський день.
     *
     * @return list<string>
     */
    private function days(object $row): array
    {
        $kyiv = new \DateTimeZone(self::MEEST_ZONE);
        $reserved = strtotime((string)($row->reserved_at ?? $row->created_at ?? ''));
        $start = new \DateTimeImmutable('@' . ($reserved === false ? ($this->now)() : $reserved))->setTimezone($kyiv);

        return [$start->format('d.m.Y'), $start->modify('+1 day')->format('d.m.Y')];
    }
}
