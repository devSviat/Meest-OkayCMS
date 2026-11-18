<?php

namespace Okay\Modules\Sviat\Meest\Cart;

use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Quote\Parcel;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/**
 * Пункти одного міста для кошика: вкладки груп із кількістю й одна сторінка обраної групи.
 * Місто тягне всі свої пункти одним запитом: у найбільшому їх сотні, тож пошук і порядок рахуються тут.
 */
final class BranchList
{
    public const int PAGE = 100;
    private const int MAX_SEARCH = 64;
    private const int MAX_WORDS = 5;
    private const array COLUMNS = [
        'b.id', 'b.number', 'b.kind', 'b.name_ua', 'b.address_ua', 'b.schedule_json',
        'b.weight_place_max', 'b.weight_total_max', 'b.insurance_max', 'b.length_max', 'b.width_max', 'b.height_max',
        'b.inbound_from', 'b.inbound_to', 'b.overloaded',
    ];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
        private readonly GroupsStore $groups,
        private readonly Settings $settings,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param float $declared оголошена цінність у гривнях
     * @return array{groups: list<array{id:int, name:string, count:int}>, group: ?int, points: list<array<string, mixed>>}
     */
    public function forCity(string $cityId, CartMetrics $cart, float $declared, ?int $groupId, string $query): array
    {
        $select = $this->queryFactory->newSelect()
            ->cols(self::COLUMNS)
            ->from(BranchEntity::getTable() . ' AS b')
            ->where('b.city_id = :city')
            // Точка лише для відправлення не видає посилок.
            ->where('b.sending_only = 0')
            ->bindValue('city', $cityId);
        $branches = $this->db->query($select) === false ? [] : $this->db->results();

        return self::build(
            $branches,
            $this->groups->all(),
            $cart,
            ShippingConfig::fromSettings($this->settings->get(...)),
            $declared,
            self::today(($this->clock)()),
            $groupId,
            $query,
        );
    }

    /** Оголошена цінність кошика, грн: за тим самим правилом, що й у розрахунку ціни. */
    public function declaredFor(CartMetrics $cart): float
    {
        return Parcel::insurance(ShippingConfig::fromSettings($this->settings->get(...)), $cart->totalUah ?? $cart->total);
    }

    /** Дата в Києві: обмеження приймання Meest задає календарними днями. */
    public static function today(int $now): string
    {
        return new \DateTimeImmutable('@' . $now)->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d');
    }

    /**
     * @param list<object> $branches рядки BranchEntity з колонками COLUMNS
     * @param list<object> $groupRows рядки GroupEntity
     * @return array{groups: list<array{id:int, name:string, count:int}>, group: ?int, points: list<array<string, mixed>>}
     */
    public static function build(array $branches, array $groupRows, CartMetrics $cart, ShippingConfig $box, float $declared, string $today, ?int $groupId, string $query): array
    {
        $counts = [];
        foreach ($branches as $branch) {
            $counts[$branch->kind] = ($counts[$branch->kind] ?? 0) + 1;
        }
        $groups = self::groupCounts($groupRows, $counts);
        $ids = array_column($groups, 'id');
        $chosen = in_array($groupId, $ids, true) ? $groupId : ($ids[0] ?? null);
        if ($chosen === null) {
            return ['groups' => $groups, 'group' => null, 'points' => []];
        }

        // Номер — рядок: у SQL порядок був би 1, 10, 100, 2.
        usort($branches, static fn(object $a, object $b): int => strnatcmp((string)$a->number, (string)$b->number) ?: strcmp((string)$a->id, (string)$b->id));
        $kinds = self::kindsOf($groupRows, $chosen);
        $words = self::searchWords($query);
        $ranked = [];
        foreach ($branches as $branch) {
            if (!in_array($branch->kind, $kinds, true)) {
                continue;
            }
            $rank = $words === null ? 0 : self::matchRank($branch, $words);
            if ($rank === null) {
                continue;
            }
            $problems = BranchFit::problems($cart, $box, $declared, $branch, $today);
            $ranked[$rank][$problems === [] ? 0 : 1][] = [$branch, $problems];
        }
        ksort($ranked);

        $points = [];
        foreach ($ranked as $parts) {
            foreach ([...($parts[0] ?? []), ...($parts[1] ?? [])] as [$branch, $problems]) {
                $points[] = self::point($branch, $problems);
            }
        }

        return ['groups' => $groups, 'group' => $chosen, 'points' => array_slice($points, 0, self::PAGE)];
    }

    /**
     * @param list<FitProblem> $problems
     * @return array{id:string, number:string, kind:string, title:string, address:string, schedule:?array, overloaded:bool, disabled:bool, reasons:list<string>, until:?string}
     */
    public static function point(object $branch, array $problems): array
    {
        return [
            'id' => (string)$branch->id,
            'number' => (string)$branch->number,
            'kind' => (string)$branch->kind,
            'title' => (string)$branch->name_ua,
            'address' => (string)($branch->address_ua ?? ''),
            'schedule' => WorkSchedule::groups((string)($branch->schedule_json ?? '')),
            'overloaded' => (bool)(int)($branch->overloaded ?? 0),
            'disabled' => $problems !== [],
            'reasons' => array_map(static fn(FitProblem $p): string => $p->value, $problems),
            'until' => in_array(FitProblem::Inbound, $problems, true) ? BranchFit::inboundUntil($branch) : null,
        ];
    }

    /**
     * @param list<object> $groupRows рядки GroupEntity
     * @param array<string, int> $counts пункти за видами
     * @return list<array{id:int, name:string, count:int}> групи, які покупець може обрати, по порядку
     */
    public static function groupCounts(array $groupRows, array $counts): array
    {
        $groups = [];
        foreach ($groupRows as $group) {
            $count = 0;
            foreach (self::kindsOf([$group], (int)$group->id) as $kind) {
                $count += $counts[$kind] ?? 0;
            }
            if ($count > 0) {
                $groups[] = ['id' => (int)$group->id, 'name' => (string)$group->name, 'count' => $count];
            }
        }

        return $groups;
    }

    /**
     * @param list<object> $groupRows
     * @return list<string> види, показані в увімкненій групі
     */
    public static function kindsOf(array $groupRows, ?int $groupId): array
    {
        foreach ($groupRows as $group) {
            if ((int)$group->id !== $groupId || !(int)$group->enabled) {
                continue;
            }
            $kinds = json_decode((string)$group->kinds, true);

            return is_array($kinds) ? array_values(array_filter($kinds, is_string(...))) : [];
        }

        return [];
    }

    /**
     * @param list<object> $groupRows
     * @return list<string> види всіх увімкнених груп
     */
    public static function visibleKinds(array $groupRows): array
    {
        $kinds = [];
        foreach ($groupRows as $group) {
            array_push($kinds, ...self::kindsOf($groupRows, (int)$group->id));
        }

        return array_values(array_unique($kinds));
    }

    /**
     * Введений пошук як слова, щонайбільше 5. "№" і коми лише розділяють: "№ 13" — це пункт 13.
     * @return ?non-empty-list<string>
     */
    public static function searchWords(string $raw): ?array
    {
        $raw = str_replace(['’', 'ʼ', '`', '‘'], "'", $raw);
        $text = mb_substr(trim(preg_replace('/[\s,№#]+/u', ' ', $raw) ?? ''), 0, self::MAX_SEARCH);
        $words = array_slice(array_values(array_filter(explode(' ', $text), static fn(string $w): bool => $w !== '')), 0, self::MAX_WORDS);

        return $words === [] ? null : $words;
    }

    /**
     * Кожне слово має знайтися в номері чи адресі ("Польова 15"). Порядок: точний номер, номери з цього
     * початку, решта. Null — пункт не підходить.
     *
     * @param non-empty-list<string> $words
     */
    private static function matchRank(object $branch, array $words): ?int
    {
        $number = (string)$branch->number;
        $haystack = (string)($branch->address_ua ?? '') . ' ' . (string)$branch->name_ua;
        foreach ($words as $word) {
            if (!self::wordMatches($word, $number, $haystack)) {
                return null;
            }
        }

        return match (true) {
            $number === $words[0] => 0,
            ctype_digit($words[0]) && str_starts_with($number, $words[0]) => 1,
            default => 2,
        };
    }

    /** Число — початок номера пункту або будинок в адресі з початку числа: "2" не знаходить будинок 12. */
    private static function wordMatches(string $word, string $number, string $haystack): bool
    {
        if (!ctype_digit($word)) {
            return mb_stripos($haystack, $word) !== false;
        }

        return str_starts_with($number, $word) || preg_match('/(?<!\d)' . $word . '/', $haystack) === 1;
    }
}
