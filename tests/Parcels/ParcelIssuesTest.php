<?php

namespace Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssue;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use PHPUnit\Framework\TestCase;

class ParcelIssuesTest extends TestCase
{
    private static function one(MeestApiException|string $problem): ParcelIssue
    {
        $issues = new ParcelIssues()->explain($problem);
        self::assertCount(1, $issues);

        return $issues[0];
    }

    /** @return array<string, string> */
    private static function lang(string $file): array
    {
        $lang = [];
        require dirname(__DIR__, 5) . "/Okay/Modules/Sviat/Meest/Backend/lang/{$file}.php";

        return $lang;
    }

    public function testAuthAdvice(): void
    {
        $issue = self::one(MeestApiException::of(ApiErrorKind::BadToken, 'POST parcel: HTTP 401'));

        self::assertSame('sviat__meest__parcel_api_bad_token', $issue->text);
        self::assertSame('sviat__meest__parcel_advice_key', $issue->advice);
        self::assertNull($issue->field);
        self::assertSame('sviat__meest__parcel_advice_key', self::one(MeestApiException::of(ApiErrorKind::NotConfigured, 'x'))->advice);
    }

    public function testRateLimitedAdvice(): void
    {
        $issue = self::one(MeestApiException::of(ApiErrorKind::RateLimited, 'Meest request budget exhausted (other)'));

        self::assertSame('sviat__meest__parcel_api_rate_limited', $issue->text);
        self::assertSame('sviat__meest__parcel_advice_retry', $issue->advice);
        self::assertStringContainsStringIgnoringCase('спробуйте за хвилину', self::lang('ua')[$issue->advice]);
    }

    public function testFieldFromMeestFieldName(): void
    {
        $cases = [
            'receiver.phone' => 'receiver_phone',
            'receiver.name' => 'receiver_name',
            'receiver.branchID' => 'point',
            'placesItems[0].weight' => 'weight',
            'placesItems.0.length' => 'sides',
            'contentsItems[0].contentName' => 'description',
            'payType' => 'pay_type',
            'receiverPay' => 'payer',
            'something.new' => null,
        ];
        foreach ($cases as $fieldName => $field) {
            $issue = self::one(MeestApiException::of(ApiErrorKind::Rejected, 'POST parcel: HTTP 400', $fieldName, 'Текст Meest'));

            self::assertSame($field, $issue->field, $fieldName);
            self::assertSame('Текст Meest', $issue->text, 'Meest text as is; the template escapes it');
        }
    }

    public function testRejectedWithoutTextFallsBackToKey(): void
    {
        $issue = self::one(MeestApiException::of(ApiErrorKind::Rejected, 'POST parcel: HTTP 400', 'receiver.phone'));

        self::assertSame('receiver_phone', $issue->field);
        self::assertSame('sviat__meest__parcel_api_rejected', $issue->text);
    }

    public function testOwnRefusalIsALanguageKey(): void
    {
        $issue = self::one('sviat__meest__parcel_exists');

        self::assertSame('sviat__meest__parcel_exists', $issue->text);
        self::assertNull($issue->field);
        self::assertNull($issue->advice);
    }

    /** Кожен мовний ключ, який повертає сервіс посилок, є в усіх трьох мовах адмінки. */
    public function testEveryKeyIsTranslated(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Meest/Parcels';
        $code = (string)file_get_contents("{$root}/ParcelIssues.php") . (string)file_get_contents("{$root}/ParcelService.php");
        preg_match_all("/'((?:sviat__meest__)?parcel_(?:api|advice|err|exists)[a-z_]*)'/", $code, $m);
        $keys = array_values(array_unique(array_map(static fn(string $k): string => str_starts_with($k, 'sviat__') ? $k : "sviat__meest__{$k}", $m[1])));
        self::assertNotEmpty($keys);

        foreach (['ua', 'en', 'ru'] as $file) {
            $lang = self::lang($file);
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $lang, "{$file}: {$key}");
            }
        }
    }
}
