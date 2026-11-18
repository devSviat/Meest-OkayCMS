<?php

namespace Modules\Sviat\Meest\Backend;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Meest\Support\InMemoryState;
use Okay\Modules\Sviat\Meest\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryStatus;
use PHPUnit\Framework\TestCase;

class DictionaryStatusViewTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private InMemoryState $state;

    protected function setUp(): void
    {
        $this->state = new InMemoryState();
    }

    private function view(): DictionaryStatusView
    {
        $counts = $this->createStub(DictionaryCounts::class);
        $counts->method('byKind')->willReturn(['branch' => 1200, 'postomat' => 300]);
        $counts->method('cities')->willReturn(900);
        $counts->method('regions')->willReturn(25);

        return new DictionaryStatusView($this->state, $counts, static fn(): int => self::NOW);
    }

    /** @param array<string, mixed> $run */
    private function lastRun(array $run): void
    {
        $status = new DictionaryStatus();
        $status->lastRun = $run + ['started' => self::NOW - 600, 'finished' => self::NOW - 300, 'status' => DictionaryStatus::OK,
            'counts' => [], 'skipped' => [], 'pending' => [], 'unknown_types' => [], 'empty_regions' => [], 'error' => null];
        $status->save($this->state);
    }

    public function testNeverRun(): void
    {
        $view = $this->view()->build();

        self::assertSame('none', $view['state']);
        self::assertNull($view['updated_at']);
    }

    public function testShowsCounts(): void
    {
        $this->lastRun([]);

        $view = $this->view()->build();

        self::assertSame('ok', $view['state']);
        self::assertSame(['regions' => 25, 'cities' => 900, 'branch' => 1200, 'postomat' => 300], $view['counts']);
        self::assertNotNull($view['updated_at']);
    }

    public function testShowsUnknownTypes(): void
    {
        $this->lastRun(['unknown_types' => ['ХХХ' => 7, 'ЯЯЯ' => 2]]);

        $view = $this->view()->build();

        self::assertSame([['type' => 'ХХХ', 'count' => 7], ['type' => 'ЯЯЯ', 'count' => 2]], $view['unknown_types']);
    }

    public function testShowsSkippedAndEmptyRegionsByName(): void
    {
        $this->lastRun([
            'status' => DictionaryStatus::PARTIAL,
            'skipped' => ['0a1b2c3d-0000-4000-8000-0000000000a1' => ['name' => 'Вигадана область', 'reason' => 'not_found']],
            'empty_regions' => ['0a1b2c3d-0000-4000-8000-0000000000a2' => 'Порожня область'],
        ]);

        $view = $this->view()->build();

        self::assertSame('partial', $view['state']);
        self::assertSame([['name' => 'Вигадана область', 'reason' => 'not_found', 'detail' => null]], $view['skipped']);
        self::assertSame(['Порожня область'], $view['empty_regions']);
    }

    public function testQueuedRequestShownAndBlocksButton(): void
    {
        $this->lastRun([]);
        DictionaryStatus::request($this->state, self::NOW - 60);

        $view = $this->view()->build();

        self::assertTrue($view['queued']);
        self::assertFalse($view['can_run']);
    }

    public function testButtonAvailableWhenIdle(): void
    {
        $this->lastRun([]);

        self::assertTrue($this->view()->build()['can_run']);
    }

    public function testFailedRunShowsTranslatedKindNotRawText(): void
    {
        $this->lastRun(['status' => DictionaryStatus::FAILED, 'error' => 'server_error']);

        $view = $this->view()->build();

        self::assertSame('failed', $view['state']);
        self::assertSame(['kind' => 'server_error', 'detail' => null], $view['error']);
    }

    public function testUnknownErrorTextShownOnlyAsDetail(): void
    {
        $this->lastRun(['status' => DictionaryStatus::FAILED, 'error' => 'SQLSTATE[HY000]: gone away']);

        self::assertSame(['kind' => 'internal', 'detail' => 'SQLSTATE[HY000]: gone away'], $this->view()->build()['error']);
    }

    public function testKeptRegionListExplainsPartialRun(): void
    {
        $note = 'regionSearch: 6 regions against 25 cached; the cached list is kept';
        $this->lastRun(['status' => DictionaryStatus::PARTIAL, 'regions_kept' => $note]);

        self::assertSame(['kind' => 'regions_kept', 'detail' => $note], $this->view()->build()['error']);
    }

    public function testSkippedRegionReasonsTranslated(): void
    {
        $this->lastRun([
            'status' => DictionaryStatus::PARTIAL,
            'skipped' => [
                'r1' => ['name' => 'Перша', 'reason' => 'rejected'],
                'r2' => ['name' => 'Друга', 'reason' => '3 rows against 40 stored; the region keeps its rows'],
            ],
        ]);

        self::assertSame([
            ['name' => 'Перша', 'reason' => 'rejected', 'detail' => null],
            ['name' => 'Друга', 'reason' => 'implausible', 'detail' => '3 rows against 40 stored; the region keeps its rows'],
        ], $this->view()->build()['skipped']);
    }

    public function testFinishedTimeOnlyForSuccessfulRun(): void
    {
        $this->lastRun(['status' => DictionaryStatus::FAILED, 'error' => 'unreachable']);
        $failed = $this->view()->build();

        self::assertNull($failed['updated_at'], 'a failure does not read as fresh data');
        self::assertNotNull($failed['attempted_at']);

        $this->lastRun(['status' => DictionaryStatus::PARTIAL]);
        $partial = $this->view()->build();

        self::assertNotNull($partial['updated_at']);
        self::assertNull($partial['attempted_at']);
    }

    public function testInterruptedRunExplained(): void
    {
        $this->lastRun(['status' => DictionaryStatus::FAILED, 'error' => 'interrupted']);

        $view = $this->view()->build();

        self::assertSame('failed', $view['state']);
        self::assertSame(['kind' => 'interrupted', 'detail' => null], $view['error']);
    }

    public function testNoKeysCarriesNoErrorText(): void
    {
        $this->lastRun(['status' => DictionaryStatus::NO_KEYS, 'error' => 'Meest token is not set']);

        $view = $this->view()->build();

        self::assertSame('no_keys', $view['state']);
        self::assertNull($view['error']);
    }
}
