<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;

/** Перетворює відмову на те, що показує форма посилки: поле для позначки, текст і пораду. */
final class ParcelIssues
{
    private const string PREFIX = 'sviat__meest__';

    /**
     * `info.fieldName` Meest (без індексів масивів, малими літерами) → поле форми. Назви — з тіла `POST parcel`
     * (ParcelDraft::toRequest); невідома назва дає відмову без позначки поля.
     */
    private const array FIELDS = [
        'receiver.name' => 'receiver_name',
        'receiver.phone' => 'receiver_phone',
        'receiver.branchid' => 'point',
        'placesitems.weight' => 'weight',
        'placesitems.placeweight' => 'weight',
        'contentsitems.weight' => 'weight',
        'placesitems.length' => 'sides',
        'placesitems.width' => 'sides',
        'placesitems.height' => 'sides',
        'placesitems.volume' => 'sides',
        'placesitems.placevolume' => 'sides',
        'placesitems.insurance' => 'declared_value',
        'contentsitems.value' => 'declared_value',
        'contentsitems.contentname' => 'description',
        'paytype' => 'pay_type',
        'receiverpay' => 'payer',
        'notation' => 'notation',
    ];

    /**
     * @param MeestApiException|string $problem відмова Meest або мовний ключ власної відмови модуля
     * @return list<ParcelIssue>
     */
    public function explain(MeestApiException|string $problem): array
    {
        if (is_string($problem)) {
            return [new ParcelIssue(null, $problem)];
        }

        $key = match ($problem->kind) {
            ApiErrorKind::BadToken => 'parcel_api_bad_token',
            ApiErrorKind::NotConfigured => 'parcel_api_not_configured',
            ApiErrorKind::RateLimited => 'parcel_api_rate_limited',
            ApiErrorKind::NotFound => 'parcel_api_not_found',
            ApiErrorKind::Unreachable => 'parcel_api_unreachable',
            ApiErrorKind::ServerError => 'parcel_api_server',
            ApiErrorKind::Malformed, ApiErrorKind::Empty => 'parcel_api_malformed',
            ApiErrorKind::Rejected => null,
        };
        if ($key !== null) {
            $advice = match ($problem->kind) {
                ApiErrorKind::BadToken, ApiErrorKind::NotConfigured => self::PREFIX . 'parcel_advice_key',
                ApiErrorKind::RateLimited => self::PREFIX . 'parcel_advice_retry',
                default => null,
            };

            return [new ParcelIssue(null, self::PREFIX . $key, $advice)];
        }

        return [new ParcelIssue(self::field($problem->fieldName), $problem->apiMessage ?? self::PREFIX . 'parcel_api_rejected')];
    }

    private static function field(?string $fieldName): ?string
    {
        if ($fieldName === null) {
            return null;
        }
        // `placesItems[0].weight` і `placesItems.0.weight` — те саме поле першого місця.
        $name = strtolower((string)preg_replace(['/\[\d+\]/', '/\.\d+(?=\.|$)/'], '', $fieldName));

        return self::FIELDS[$name] ?? null;
    }
}
