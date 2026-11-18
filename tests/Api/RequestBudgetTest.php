<?php

namespace Modules\Sviat\Meest\Api;

require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';

use Modules\Sviat\Meest\Support\InMemoryMutex;
use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Api\MeestApiException;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Storage\Mutex;
use PHPUnit\Framework\TestCase;

class RequestBudgetTest extends TestCase
{
    private const int START = 1_800_000_000;

    private InMemoryState $state;
    private InMemoryMutex $mutex;
    private int $now = self::START;

    protected function setUp(): void
    {
        $this->state = new InMemoryState();
        $this->mutex = new InMemoryMutex();
        $this->now = self::START;
    }

    private function budget(?Mutex $mutex = null): RequestBudget
    {
        return new RequestBudget($this->state, $mutex ?? $this->mutex, fn(): int => $this->now);
    }

    private function take(RequestBudget $budget, string $slot, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $budget->take($slot);
        }
    }

    private function assertRefused(RequestBudget $budget, string $slot): void
    {
        try {
            $budget->take($slot);
            self::fail('expected RateLimited');
        } catch (MeestApiException $e) {
            self::assertSame(ApiErrorKind::RateLimited, $e->kind);
        }
    }

    public function testFourHundredFirstRequestRefused(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::OTHER, 400);

        $this->assertRefused($budget, RequestBudget::OTHER);
        $this->assertRefused($budget, RequestBudget::CART);
        self::assertSame([], $this->mutex->held);
    }

    public function testTwoHundredFirstCartRequestRefused(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::CART, 200);

        $this->assertRefused($budget, RequestBudget::CART);
    }

    public function testManagerPassesAfterCartUsedItsShare(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::CART, 200);
        $this->assertRefused($budget, RequestBudget::CART);

        $this->take($budget, RequestBudget::OTHER, 200);
        $this->assertRefused($budget, RequestBudget::OTHER);
    }

    public function testCartCountsTowardsTotal(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::OTHER, 250);
        $this->take($budget, RequestBudget::CART, 150);

        $this->assertRefused($budget, RequestBudget::CART);
        $this->assertRefused($budget, RequestBudget::OTHER);
    }

    public function testWindowResetsAfterMinute(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::OTHER, 200);
        $this->take($budget, RequestBudget::CART, 200);

        $this->now = self::START + RequestBudget::WINDOW - 1;
        $this->assertRefused($budget, RequestBudget::OTHER);

        $this->now = self::START + RequestBudget::WINDOW;
        $budget->take(RequestBudget::CART);
        self::assertSame(['window_start' => self::START + 60, 'all' => 1, 'cart' => 1], json_decode((string)$this->state->get('request_budget'), true));
    }

    public function testSharedAcrossInstances(): void
    {
        $this->take($this->budget(), RequestBudget::OTHER, 399);
        $this->budget()->take(RequestBudget::OTHER);

        $this->assertRefused($this->budget(), RequestBudget::OTHER);
    }

    public function testGarbageStateStartsFreshWindow(): void
    {
        $this->state->set('request_budget', '{"all":"x"');
        $this->budget()->take(RequestBudget::OTHER);

        self::assertSame(['window_start' => self::START, 'all' => 1, 'cart' => 0], json_decode((string)$this->state->get('request_budget'), true));
    }

    public function testLockWaitsOneSecond(): void
    {
        $mutex = new class implements Mutex {
            /** @var list<array{string, int}> */
            public array $acquired = [];
            /** @var list<string> */
            public array $released = [];

            public function acquire(string $name, int $waitSeconds = 0): bool
            {
                $this->acquired[] = [$name, $waitSeconds];

                return true;
            }

            public function release(string $name): void
            {
                $this->released[] = $name;
            }
        };

        $this->budget($mutex)->take(RequestBudget::OTHER);

        self::assertSame([['budget', 1]], $mutex->acquired);
        self::assertSame(['budget'], $mutex->released);
    }

    public function testNoLockRefuses(): void
    {
        $this->mutex->held['budget'] = true;

        $this->assertRefused($this->budget(), RequestBudget::OTHER);
        self::assertNull($this->state->get('request_budget'));
    }
}
