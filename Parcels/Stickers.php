<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\MeestClient;

/** Стікери 100×100: байти PDF віддає адмін-контролер, token не потрапляє в браузер. */
final class Stickers
{
    public const int MAX = 20;

    private const string PATH = 'print/sticker100/';

    public function __construct(
        private readonly MeestClient $client,
        private readonly ParcelRows $rows,
    ) {
    }

    /**
     * @throws StickerIssue замовлення без посилки, яку можна надрукувати
     * @throws MeestApiException відмова Meest чи відповідь, що не є PDF
     */
    public function one(int $orderId): StickerFile
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null || PendingParcel::is($row)) {
            throw new StickerIssue('sviat__meest__sticker_err_none');
        }
        [$parcelId, $barcode, $contractId] = $this->identity($row);
        if ($parcelId !== '') {
            $pdf = $this->client->pdf(self::PATH . rawurlencode($parcelId));
        } elseif ($barcode !== '' && $contractId !== '') {
            $pdf = $this->client->pdf(self::PATH . rawurlencode($barcode), ['agent' => $contractId]);
        } else {
            throw new StickerIssue('sviat__meest__sticker_err_none');
        }

        return StickerFile::fromPdfs([$pdf], $this->stamp());
    }

    /**
     * Замовлення без придатної посилки пропускаються з причиною: пакетний друк не має падати через одне з них.
     * Рядки з parcel_id йдуть одним запитом, рядки лише зі штрихкодом — окремим на кожен договір,
     * бо `agent` задається на весь запит.
     *
     * @param list<int> $orderIds
     * @return array{file: ?StickerFile, skipped: array<int, string>} причини — мовні ключі за id замовлення
     * @throws StickerIssue замовлень більше за MAX
     * @throws MeestApiException відмова Meest чи відповідь, що не є PDF
     */
    public function many(array $orderIds): array
    {
        $orderIds = array_values(array_unique($orderIds));
        if (count($orderIds) > self::MAX) {
            throw new StickerIssue('sviat__meest__sticker_err_too_many');
        }

        $skipped = [];
        $byParcelId = [];
        $byContract = [];
        // forOrders() віддає лише колонки рядка списку, без parcel_id і contract_id; замовлень не більше MAX.
        foreach ($orderIds as $orderId) {
            $row = $this->rows->forOrder($orderId);
            if ($row === null) {
                $skipped[$orderId] = 'sviat__meest__sticker_skip_no_parcel';
                continue;
            }
            if (PendingParcel::is($row)) {
                $skipped[$orderId] = 'sviat__meest__sticker_skip_pending';
                continue;
            }
            [$parcelId, $barcode, $contractId] = $this->identity($row);
            if ($parcelId !== '') {
                $byParcelId[] = rawurlencode($parcelId);
            } elseif ($barcode === '') {
                $skipped[$orderId] = 'sviat__meest__sticker_skip_no_parcel';
            } elseif ($contractId === '') {
                $skipped[$orderId] = 'sviat__meest__sticker_skip_no_contract';
            } else {
                $byContract[$contractId][] = rawurlencode($barcode);
            }
        }

        $pdfs = [];
        if ($byParcelId !== []) {
            $pdfs[] = $this->client->pdf(self::PATH . implode(',', $byParcelId));
        }
        foreach ($byContract as $contractId => $barcodes) {
            $pdfs[] = $this->client->pdf(self::PATH . implode(',', $barcodes), ['agent' => (string)$contractId]);
        }

        return ['file' => $pdfs === [] ? null : StickerFile::fromPdfs($pdfs, $this->stamp()), 'skipped' => $skipped];
    }

    /** @return array{string, string, string} parcel_id, штрихкод у формі Meest, договір рядка */
    private function identity(object $row): array
    {
        return [
            is_string($row->parcel_id ?? null) ? $row->parcel_id : '',
            is_string($row->barcode ?? null) ? $row->barcode : '',
            is_string($row->contract_id ?? null) ? $row->contract_id : '',
        ];
    }

    private function stamp(): string
    {
        return date('Ymd-His');
    }
}
