<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Psr\Log\LoggerInterface;

/**
 * Створює, видаляє, прив'язує й відв'язує посилку замовлення. POST parcel надсилається рівно один раз на резерв:
 * після обриву Meest могла вже створити посилку, а `parcelNumber` дублів не відсікає.
 * У лог іде лише id замовлення й вид помилки: ні тіла запиту, ні ПІБ чи телефону одержувача.
 */
final class ParcelService
{
    /** Статус Meest «створено, ще не прийнято»: лише в ньому посилку можна видалити. */
    private const int STATUS_DELETABLE = 2;

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

    /** @param ParcelConfig $config налаштування, з якими будувалася чернетка: відправник і договір тіла запиту */
    public function create(int $orderId, ParcelDraft $draft, ParcelConfig $config): ParcelOutcome
    {
        $errors = $draft->errors();
        if ($errors !== []) {
            return ParcelOutcome::failed(array_map(
                static fn(string $field, string $key): ParcelIssue => new ParcelIssue($field, $key),
                array_keys($errors),
                array_values($errors),
            ));
        }
        $occupied = $this->occupied($this->rows->forOrder($orderId));
        if ($occupied !== null) {
            return $occupied;
        }
        // UNIQUE order_id у reserve() лишається захистом від двох одночасних запитів, що обидва пройшли перевірку вище.
        $parcelNumber = $this->rows->reserve($orderId, date('Y-m-d H:i:s', ($this->now)()));
        if ($parcelNumber === null) {
            return $this->occupied($this->rows->forOrder($orderId)) ?? ParcelOutcome::refused('sviat__meest__parcel_err_reserve');
        }

        try {
            $answer = $this->client->call('POST', 'parcel', $draft->toRequest($parcelNumber, $orderId, $config));
        } catch (MeestApiException $e) {
            if (self::mayExist($e)) {
                $this->rows->markUncertain($orderId);
                $this->logger->warning("Meest parcel for order {$orderId}: result unknown, kind={$e->kind->value}");

                return ParcelOutcome::failed([new ParcelIssue(null, 'sviat__meest__parcel_err_uncertain'), ...$this->issues->explain($e)]);
            }
            // Meest відмовила чи запит не пішов: посилки немає, замовлення знову вільне.
            $this->rows->release($orderId);

            return ParcelOutcome::failed($this->issues->explain($e));
        } catch (\Throwable $e) {
            // Невідомо, чи запит устиг піти: замовлення чекає на пошук, а не на повтор.
            $this->rows->markUncertain($orderId);

            throw $e;
        }

        $parcelId = self::uuid($answer['parcelID'] ?? null);
        if ($parcelId === null) {
            $this->rows->markUncertain($orderId);
            $this->logger->warning("Meest parcel for order {$orderId}: answer without parcelID");

            return ParcelOutcome::refused('sviat__meest__parcel_err_uncertain');
        }
        $barcode = is_string($answer['barCode'] ?? null) ? Barcode::parse($answer['barCode']) : null;
        $saved = $this->rows->complete($orderId, [
            'parcel_id' => $parcelId,
            'barcode' => $barcode,
            'delivery_price' => self::money($answer['costServices'] ?? null),
            'declared_value' => round($draft->declaredValue, 2),
            'receiver_pay' => $draft->receiverPay ? 1 : 0,
            'pay_type' => $draft->payType,
            'contract_id' => $config->contractId !== '' ? $config->contractId : null,
            // Що надіслали, а не що повернули: відповідь створення повної посилки не обіцяє.
            'details' => ParcelDetails::encode(ParcelDetails::fromDraft($draft, $answer)),
        ]);
        if (!$saved) {
            // Посилка в Meest є, а запис не вдався: звільнене замовлення дало б створити другу.
            $this->rows->markUncertain($orderId);
            $this->logger->warning("Meest parcel for order {$orderId}: created but not saved");

            return ParcelOutcome::refused('sviat__meest__parcel_err_not_saved', $barcode ?? $parcelId);
        }
        $this->logger->info("Meest parcel for order {$orderId} created");

        return new ParcelOutcome(true, $barcode);
    }

    /**
     * Meest видаляє лише посилку в статусі 2 і лише за `parcelID`: прив'язана за штрихкодом його не має.
     * Група ставиться трекінгом, тож і вона мусить бути ще `created`.
     */
    public static function deletable(object $row): bool
    {
        $status = $row->status_code ?? null;

        return ($row->state ?? null) === PendingParcel::CREATED
            && is_string($row->parcel_id ?? null) && $row->parcel_id !== ''
            && ($status === null || $status === '' || (int)$status === self::STATUS_DELETABLE)
            && in_array($row->status_group ?? null, [null, '', 'created'], true);
    }

    public function remove(int $orderId): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_none');
        }
        if (!self::deletable($row)) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_not_deletable');
        }
        // Договір рядка, а не поточний: посилка належить договору, з яким її створено.
        $contract = is_string($row->contract_id ?? null) ? $row->contract_id : '';
        $path = 'parcel/' . rawurlencode((string)$row->parcel_id) . ($contract !== '' ? '/' . rawurlencode($contract) : '');
        try {
            $this->client->call('DELETE', $path);
        } catch (MeestApiException $e) {
            // 4xx на видалення означає, що Meest уже прийняла посилку: кнопка зникне після оновлення статусу.
            $refused = $e->kind === ApiErrorKind::Rejected ? [new ParcelIssue(null, 'sviat__meest__parcel_err_delete_refused')] : [];

            return ParcelOutcome::failed([...$refused, ...$this->issues->explain($e)]);
        }
        $this->rows->remove($orderId);
        $this->logger->info("Meest parcel for order {$orderId} deleted");

        return new ParcelOutcome(true, is_string($row->barcode ?? null) ? $row->barcode : null);
    }

    /**
     * Забуває посилку замовлення, не питаючи Meest: прийняту (її не видалити) або невідоме створення, якого
     * менеджер не знайшов. У Meest вона лишається.
     */
    public function unlink(int $orderId): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_none');
        }
        if (PendingParcel::running($row, ($this->now)())) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_pending');
        }
        $this->rows->remove($orderId);

        return new ParcelOutcome(true, is_string($row->barcode ?? null) ? $row->barcode : null);
    }

    /**
     * Прив'язує посилку, створену деінде, за штрихкодом. Перевірка — `GET tracking/{barCode}`: на чужу посилку Meest
     * відповідає 404. `parcel_id` лишається порожнім (друк за штрихкодом), платник невідомий.
     */
    public function attach(int $orderId, string $input): ParcelOutcome
    {
        $barcode = Barcode::parse($input);
        if ($barcode === null) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_barcode');
        }
        $row = $this->rows->forOrder($orderId);
        if ($row !== null && !PendingParcel::needsCheck($row, ($this->now)())) {
            return $this->occupied($row) ?? ParcelOutcome::refused('sviat__meest__parcel_exists');
        }
        $owner = $this->rows->orderOfBarcode($barcode);
        if ($owner !== null && $owner !== $orderId) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_barcode_elsewhere', (string)$owner);
        }

        try {
            $items = MeestClient::rows($this->client->call('GET', 'tracking/' . rawurlencode($barcode)));
        } catch (MeestApiException $e) {
            return $e->kind === ApiErrorKind::NotFound
                ? ParcelOutcome::refused('sviat__meest__parcel_err_not_ours')
                : ParcelOutcome::failed($this->issues->explain($e));
        }
        if ($items === []) {
            return ParcelOutcome::refused('sviat__meest__parcel_err_not_ours');
        }
        $events = TrackingAnswer::events($items, $barcode);
        // Зберігається форма Meest (`711-2550636`), а з SMS штрихкод вводять без дефіса.
        $barcode = $events === [] ? $barcode : $events[0]->barcode;

        $contract = $this->credentials->get()->contractId;
        $now = date('Y-m-d H:i:s', ($this->now)());
        $values = [
            'parcel_id' => null,
            'barcode' => $barcode,
            'receiver_pay' => null,
            'contract_id' => $contract !== '' ? $contract : null,
            'created_at' => $now,
            'checked_at' => $now,
        ] + ($events === [] ? [] : TrackingAnswer::values($events, null, null));
        // Як і після create(): посилка без жодної відомої події вважається створеною, а не без групи.
        $values['status_group'] ??= 'created';
        $saved = $this->rows->attach($orderId, $values);
        if (!$saved) {
            // Штрихкод унікальний: тим часом інший запит міг прив'язати його до іншого замовлення.
            $owner = $this->rows->orderOfBarcode($barcode);

            return $owner !== null && $owner !== $orderId
                ? ParcelOutcome::refused('sviat__meest__parcel_err_barcode_elsewhere', (string)$owner)
                : ParcelOutcome::refused('sviat__meest__parcel_err_pending');
        }
        $this->logger->info("Meest parcel for order {$orderId} attached by barcode");

        return new ParcelOutcome(true, $barcode);
    }

    /** Відмова, коли замовлення вже має рядок; null — вільне. */
    private function occupied(?object $row): ?ParcelOutcome
    {
        return match (true) {
            $row === null => null,
            PendingParcel::needsCheck($row, ($this->now)()) => ParcelOutcome::refused('sviat__meest__parcel_err_uncertain'),
            PendingParcel::is($row) => ParcelOutcome::refused('sviat__meest__parcel_err_pending'),
            default => ParcelOutcome::refused('sviat__meest__parcel_exists', is_string($row->barcode ?? null) ? $row->barcode : null),
        };
    }

    /** Після цих запит міг дійти, і посилка могла з'явитися: обрив, 5xx, 2xx з незрозумілим тілом. */
    private static function mayExist(MeestApiException $e): bool
    {
        return in_array($e->kind, [ApiErrorKind::Unreachable, ApiErrorKind::ServerError, ApiErrorKind::Malformed, ApiErrorKind::Empty], true);
    }

    public static function uuid(mixed $value): ?string
    {
        // parcelID іде в шляхи видалення й друку: лише форма UUID.
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }

    private static function money(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? round((float)$value, 2) : null;
    }
}
