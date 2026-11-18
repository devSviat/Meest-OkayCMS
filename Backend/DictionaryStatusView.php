<?php

namespace Okay\Modules\Sviat\Meest\Backend;

use Okay\Modules\Sviat\Meest\Api\ApiErrorKind;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/** Дані шаблону блоку довідників. */
final class DictionaryStatusView
{
    /** Завдання планувальника йде кожні 5 хвилин; запит, старший за три такти, означає, що воно не працює. */
    private const int SCHEDULER_SILENT_AFTER = 15 * 60;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly StateStorage $state,
        private readonly DictionaryCounts $counts,
        private readonly \Closure $now,
    ) {
    }

    /**
     * Помилки — вид для перекладу (`sviat__meest__dict_err_{kind}`) і, якщо є, технічний текст для показу сірим.
     *
     * @return array{
     *     state: string,
     *     updated_at: ?string,
     *     attempted_at: ?string,
     *     counts: array{regions:int, cities:int, branch:int, postomat:int},
     *     queued: bool,
     *     scheduler_idle: bool,
     *     can_run: bool,
     *     error: ?array{kind: string, detail: ?string},
     *     skipped: list<array{name:string, reason:string, detail:?string}>,
     *     empty_regions: list<string>,
     *     unknown_types: list<array{type:string, count:int}>
     * }
     */
    public function build(): array
    {
        $status = DictionaryStatus::load($this->state);
        $now = ($this->now)();
        $run = $status->lastRun;
        $byKind = $this->counts->byKind();
        $running = $status->runningSince !== null && $now - $status->runningSince < DictionaryRunner::TASK_TIMEOUT;
        $queued = $status->requestedAt !== null;
        $runStatus = is_string($run['status'] ?? null) ? $run['status'] : null;
        $finished = is_int($run['finished'] ?? null) ? $run['finished'] : null;
        $succeeded = in_array($runStatus, [DictionaryStatus::OK, DictionaryStatus::PARTIAL], true);

        $skipped = [];
        foreach (is_array($run['skipped'] ?? null) ? $run['skipped'] : [] as $region) {
            if (is_array($region)) {
                $reason = (string)($region['reason'] ?? '');
                // Не вид помилки API може прийти лише від ImplausibleRegion: його текст англійський і технічний.
                $skipped[] = ApiErrorKind::tryFrom($reason) !== null
                    ? ['name' => (string)($region['name'] ?? ''), 'reason' => $reason, 'detail' => null]
                    : ['name' => (string)($region['name'] ?? ''), 'reason' => 'implausible', 'detail' => $reason];
            }
        }
        $unknown = [];
        foreach ($status->unknownTypes() as $type => $count) {
            $unknown[] = ['type' => (string)$type, 'count' => $count];
        }

        return [
            'state' => $running ? 'running' : ($runStatus ?? 'none'),
            'updated_at' => $succeeded && $finished !== null ? $this->time($finished) : null,
            'attempted_at' => !$succeeded && $finished !== null ? $this->time($finished) : null,
            'counts' => [
                'regions' => $this->counts->regions(),
                'cities' => $this->counts->cities(),
                'branch' => (int)($byKind['branch'] ?? 0),
                'postomat' => (int)($byKind['postomat'] ?? 0),
            ],
            'queued' => $queued,
            'scheduler_idle' => $queued && $now - (int)$status->requestedAt >= self::SCHEDULER_SILENT_AFTER,
            'can_run' => !$running && !$queued,
            'error' => $this->error($run, $runStatus),
            'skipped' => $skipped,
            'empty_regions' => array_values($status->emptyRegions()),
            'unknown_types' => $unknown,
        ];
    }

    /**
     * @param array<string, mixed>|null $run
     * @return array{kind: string, detail: ?string}|null
     */
    private function error(?array $run, ?string $runStatus): ?array
    {
        // Без token стан уже все пояснює, а текст runner-а англійський.
        if ($run === null || $runStatus === DictionaryStatus::NO_KEYS) {
            return null;
        }
        $error = $run['error'] ?? null;
        if (is_string($error) && $error !== '') {
            // `interrupted` пише runner, коли процес попереднього запуску вбили.
            return ApiErrorKind::tryFrom($error) !== null || $error === 'interrupted'
                ? ['kind' => $error, 'detail' => null]
                : ['kind' => 'internal', 'detail' => $error];
        }
        $kept = $run['regions_kept'] ?? null;

        return is_string($kept) && $kept !== '' ? ['kind' => 'regions_kept', 'detail' => $kept] : null;
    }

    private function time(int $at): string
    {
        return new \DateTimeImmutable('@' . $at)->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
    }
}
