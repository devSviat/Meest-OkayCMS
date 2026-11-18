<?php

namespace Modules\Sviat\Meest\Support;

require_once __DIR__ . '/FakeTransport.php';
require_once __DIR__ . '/FixedCredentials.php';
require_once __DIR__ . '/InMemoryState.php';
require_once __DIR__ . '/InMemoryMutex.php';
require_once __DIR__ . '/InMemoryParcelRows.php';
require_once __DIR__ . '/ArrayLogger.php';

use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Parcels\MassOrders;
use Okay\Modules\Sviat\Meest\Parcels\MassParcels;
use Okay\Modules\Sviat\Meest\Parcels\ParcelConfig;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDraft;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\ParcelService;
use Okay\Modules\Sviat\Meest\Parcels\Stickers;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/** Масові дії списку замовлень на справжніх ParcelService і Stickers із підставними HTTP, рядками й замовленнями. */
trait MassWorld
{
    private const string CONTRACT = 'dddddddd-0000-4000-8000-00000000000b';

    private FakeTransport $transport;
    private InMemoryParcelRows $rows;
    private ArrayLogger $logger;
    /** @var list<int> замовлення з доставкою не цього модуля */
    private array $foreign = [];
    /** @var array<int, ParcelDraft|string> чернетка замовлення; решта отримує MassWorldDraft::valid() */
    private array $drafts = [];
    /** @var list<float> відповіді годинника по черзі; останнє значення повторюється */
    private array $ticks = [];
    private int $now = 1_800_000_000;
    private ?Credentials $credentials = null;

    private function setUpWorld(): void
    {
        $this->transport = new FakeTransport();
        $this->rows = new InMemoryParcelRows();
        $this->logger = new ArrayLogger();
    }

    private function client(): MeestClient
    {
        $credentials = new FixedCredentials($this->credentials ?? new Credentials('cabinet-token-abc', self::CONTRACT));
        $state = new InMemoryState();
        $now = fn(): int => $this->now;

        return new MeestClient(
            $this->transport,
            new TokenProvider($credentials, $state, new AuthFlags($state), $now),
            new RequestBudget($state, new InMemoryMutex(), $now),
            $credentials,
            $this->logger,
        );
    }

    private function service(): ParcelService
    {
        $credentials = new FixedCredentials($this->credentials ?? new Credentials('cabinet-token-abc', self::CONTRACT));

        return new ParcelService($this->client(), $this->rows, $credentials, new ParcelIssues(), $this->logger, fn(): int => $this->now);
    }

    private function stickers(): Stickers
    {
        return new Stickers($this->client(), $this->rows);
    }

    private function mass(): MassParcels
    {
        $orders = new class ($this->foreign, $this->drafts) implements MassOrders {
            /**
             * @param list<int> $foreign
             * @param array<int, ParcelDraft|string> $drafts
             */
            public function __construct(private array $foreign, private array $drafts)
            {
            }

            public function isOurs(int $orderId): bool
            {
                return !in_array($orderId, $this->foreign, true);
            }

            public function draft(int $orderId): ParcelDraft|string
            {
                return $this->drafts[$orderId] ?? MassWorldDraft::valid();
            }

            public function config(): ParcelConfig
            {
                return MassWorldDraft::config();
            }
        };
        $clock = $this->ticks === [] ? null : function (): float {
            return count($this->ticks) > 1 ? array_shift($this->ticks) : $this->ticks[0];
        };

        return new MassParcels($this->service(), $this->rows, $orders, $this->logger, $clock);
    }

    private function createdAnswer(string $parcelId, string $barcode): void
    {
        $this->transport->json(200, ['status' => 'OK', 'info' => ['fieldName' => '', 'message' => '', 'messageDetails' => ''], 'result' => [
            'parcelID' => $parcelId,
            'barCode' => $barcode,
            'costServices' => 75.0,
        ]]);
    }

    /** @param array<string, mixed> $values */
    private function createdRow(int $orderId, array $values): void
    {
        if (!$this->rows->attach($orderId, $values)) {
            throw new \LogicException("Row for order {$orderId} not stored");
        }
    }
}

final class MassWorldDraft
{
    public static function valid(float $weight = 2.5): ParcelDraft
    {
        $branch = (object)[
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'weight_place_max' => '30.00', 'weight_total_max' => '30.00',
            'insurance_max' => '50000.00', 'length_max' => 120, 'width_max' => 60, 'height_max' => 60, 'sending_only' => 0,
        ];

        return new ParcelDraft('Тестенко Тест', '+380671234567', $branch, $weight, 30, 20, 15, 1000.0, true, 'noncash', 'Одяг', '');
    }

    public static function config(): ParcelConfig
    {
        $stored = [
            ShippingConfig::SENDER_NAME => 'Магазин Тестовий',
            ShippingConfig::SENDER_PHONE => '0501112233',
            ShippingConfig::SENDER_BRANCH_ID => 'cccccccc-0000-4000-8000-000000000003',
            Credentials::CONTRACT_ID => 'dddddddd-0000-4000-8000-00000000000b',
        ];

        return ParcelConfig::fromSettings(static fn(string $key): mixed => $stored[$key] ?? null);
    }
}
