<?php

namespace Modules\Sviat\Meest\Api;

require_once __DIR__ . '/../Support/FakeTransport.php';

use Modules\Sviat\Meest\Support\FakeTransport;
use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\Http\HttpTransport;
use Okay\Modules\Sviat\Meest\Api\Http\TransportException;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\SendsRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SendsRequestsTest extends TestCase
{
    private function client(): object
    {
        return new class {
            use SendsRequests;

            /** @param list<string> $secrets */
            public function call(HttpTransport $http, array $secrets = ['tok-123']): \Okay\Modules\Sviat\Meest\Api\Http\HttpResponse
            {
                return $this->deliver($http, new HttpRequest('POST', 'https://api.meest.com/v3.0/openAPI/citySearch', ['token' => 'tok-123']), $secrets);
            }
        };
    }

    public function testTransportTextIsMasked(): void
    {
        $http = new FakeTransport()->push(new TransportException('cURL: refused, token header tok-123 sent'));

        try {
            $this->client()->call($http);
            self::fail('expected an exception');
        } catch (MeestApiException $e) {
            self::assertSame(ApiErrorKind::Unreachable, $e->kind);
            self::assertStringNotContainsString('tok-123', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testSuccessIsReturned(): void
    {
        $response = $this->client()->call(new FakeTransport()->json(200, ['status' => 'OK']));

        self::assertSame(200, $response->status);
    }

    /** @return iterable<string, array{int, ApiErrorKind}> */
    public static function statuses(): iterable
    {
        yield '401' => [401, ApiErrorKind::BadToken];
        yield '404' => [404, ApiErrorKind::NotFound];
        yield '429' => [429, ApiErrorKind::RateLimited];
        yield '500' => [500, ApiErrorKind::ServerError];
        yield '400' => [400, ApiErrorKind::Rejected];
    }

    #[DataProvider('statuses')]
    public function testStatusMapsToKind(int $status, ApiErrorKind $kind): void
    {
        $http = new FakeTransport()->json($status, ['status' => 'Error', 'info' => ['fieldName' => 'receiver.phone', 'message' => 'bad tok-123']]);

        try {
            $this->client()->call($http);
            self::fail('expected an exception');
        } catch (MeestApiException $e) {
            self::assertSame($kind, $e->kind);
            self::assertStringNotContainsString('tok-123', $e->getMessage());
            self::assertStringNotContainsString('tok-123', (string) $e->apiMessage);
            self::assertSame('receiver.phone', $e->fieldName);
        }
    }

    public function testTraceCarriesNoArguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->client()->call(new FakeTransport()->push(new TransportException('x')));
            self::fail('expected an exception');
        } catch (MeestApiException $e) {
            self::assertNotSame([], $e->getTrace());
            foreach ($e->getTrace() as $frame) {
                self::assertArrayNotHasKey('args', $frame);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testHtmlErrorBodyIsServerErrorWithoutWarning(): void
    {
        $http = new FakeTransport()->push(new HttpResponse(502, '<html><body>Bad Gateway</body></html>'));

        try {
            $this->client()->call($http);
            self::fail('expected an exception');
        } catch (MeestApiException $e) {
            self::assertSame(ApiErrorKind::ServerError, $e->kind);
            self::assertNull($e->apiMessage);
            self::assertNull($e->fieldName);
        }
    }
}
