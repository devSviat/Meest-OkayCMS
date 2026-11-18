<?php

namespace Okay\Modules\Sviat\Meest\Orders;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Cart\BranchFit;
use Okay\Modules\Sviat\Meest\Cart\BranchList;
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Cart\CartSelection;
use Okay\Modules\Sviat\Meest\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Meest\Cart\FitProblem;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Parcels\ParcelRows;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use Okay\Modules\Sviat\Meest\Quote\DeliveryQuote;
use Okay\Modules\Sviat\Meest\Quote\Parcel;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/** Менеджер змінює пункт видачі замовлення в адмінці. */
final class OrderPointChange
{
    /** Помилки кошика (мовні ключі вітрини) як ключі адмінки. */
    private const array ERRORS = [
        'sviat__meest__err_dictionary' => 'op_err_dictionary',
        'sviat__meest__err_city' => 'op_err_city',
        'sviat__meest__err_branch' => 'op_err_branch',
        'sviat__meest__err_branch_hidden' => 'op_err_branch',
        'sviat__meest__err_branch_city' => 'op_err_branch_city',
    ];
    /**
     * Вага, габарити й цінність — рішення менеджера (він знає справжню посилку), тож лише попередження;
     * обмеження приймання пункту Meest не обійти, тож воно відхиляє вибір.
     */
    private const array WARNINGS = [
        FitProblem::TooHeavy->value => 'op_warn_too_heavy',
        FitProblem::TooBig->value => 'op_warn_too_big',
        FitProblem::OverInsurance->value => 'op_warn_insurance',
    ];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly GroupsStore $groups,
        private readonly OrderDeliveryStore $snapshots,
        private readonly OrderParcel $parcel,
        private readonly CartQuotes $quotes,
        private readonly OrderDeliveryLines $lines,
        private readonly Settings $settings,
        private readonly ParcelRows $parcels,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @param array{city_id?: string, branch_id?: string} $input */
    public function save(int $orderId, array $input): OrderPointResult
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        $delivery = $order ? $this->lines->ownDelivery($order) : null;
        if (!$order || !$delivery) {
            return OrderPointResult::failed('order_point_foreign');
        }
        [$cityId, $branchId] = CartSelectionValidator::postedIds(
            [CartSelectionValidator::CITY_FIELD => [0 => $input['city_id'] ?? ''], CartSelectionValidator::BRANCH_FIELD => [0 => $input['branch_id'] ?? '']],
            0,
        );
        $city = $cityId !== '' ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $branch = $branchId !== '' ? $this->entityFactory->get(BranchEntity::class)->get($branchId) : null;
        $metrics = $this->parcel->metrics($order);
        $config = ShippingConfig::fromSettings($this->settings->get(...));
        $chosen = self::select(
            $delivery,
            (int)$this->module->getModuleIdByNamespace(__CLASS__),
            $city ?: null,
            $branch ?: null,
            $this->groups->all(),
            $metrics,
            $config,
            Parcel::insurance($config, $metrics->totalUah ?? $metrics->total),
            BranchList::today(($this->clock)()),
            $this->parcels->forOrder($orderId),
        );
        if ($chosen['selection'] === null) {
            return OrderPointResult::failed((string)$chosen['error']);
        }
        [$price, $quote] = $this->price($order, $delivery, $branchId);
        $this->snapshots->save($orderId, $chosen['selection'], ['estimated_date' => OrderDeliveryStore::pricing($quote, null, null)['estimated_date']]);
        $auto = self::autoPrice($price);
        if ($auto !== null) {
            $this->writePrice($orderId, $auto);
        }

        return new OrderPointResult(true, null, $chosen['warning'], $price);
    }

    /** Записує тариф до поточного пункту замовлення; лише коли правило його пропонує, ніколи — надіслану суму. */
    public function applyPrice(int $orderId): OrderPointResult
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        $delivery = $order ? $this->lines->ownDelivery($order) : null;
        $snapshot = $order ? $this->snapshots->forOrder($orderId) : null;
        if (!$order || !$delivery || !$snapshot) {
            return OrderPointResult::failed('order_point_foreign');
        }
        [$price] = $this->price($order, $delivery, (string)$snapshot->branch_id);
        if ($price['action'] !== 'offer') {
            return new OrderPointResult(false, 'order_point_price_not_offered', null, $price);
        }
        $this->writePrice($orderId, (float)$price['price']);

        return new OrderPointResult(true, null, null, ['action' => 'applied', 'price' => $price['price']]);
    }

    /**
     * Перевіряє обраний пункт так само, як кошик; телефон не перевіряється: менеджер може його виправити.
     *
     * @param list<object> $groupRows рядки GroupEntity
     * @param ?object $parcel рядок посилки замовлення
     * @return array{selection: ?CartSelection, error: ?string, warning: ?string}
     */
    public static function select(object $delivery, int $moduleId, ?object $city, ?object $branch, array $groupRows, CartMetrics $metrics, ShippingConfig $box, float $declared, string $today, ?object $parcel = null): array
    {
        $fail = static fn(string $error): array => ['selection' => null, 'error' => $error, 'warning' => null];
        if (self::locks($parcel)) {
            return $fail(PendingParcel::is($parcel) ? 'op_err_locked_check' : 'op_err_locked');
        }
        if ($moduleId === 0 || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return $fail('order_point_foreign');
        }
        $checked = CartSelectionValidator::check($delivery, $moduleId, $city, $branch, $metrics, $box, $declared, $today, $groupRows);
        if ($checked instanceof CartSelection) {
            return $checked->applies() ? ['selection' => $checked, 'error' => null, 'warning' => null] : $fail('order_point_foreign');
        }
        if (isset(self::ERRORS[$checked]) || $branch === null || $city === null) {
            return $fail(self::ERRORS[$checked] ?? 'op_err_branch');
        }
        $problems = BranchFit::problems($metrics, $box, $declared, $branch, $today);
        if ($problems === []) {
            return $fail('op_err_branch');
        }
        if (in_array(FitProblem::Inbound, $problems, true)) {
            return $fail('op_err_inbound');
        }

        return ['selection' => new CartSelection($delivery, $city, $branch), 'error' => null, 'warning' => self::WARNINGS[$problems[0]->value]];
    }

    /**
     * Посилка йде на пункт, з яким її створено, а невідоме створення могло вже дати посилку: будь-який рядок
     * посилки закриває зміну пункту, доки його не видалено чи не відв'язано.
     */
    public static function locks(?object $parcel): bool
    {
        return $parcel !== null;
    }

    /** Сума, яку зміна пункту записує в замовлення сама; решту менеджер лише бачить або застосовує кнопкою. */
    public static function autoPrice(array $decision): ?float
    {
        return ($decision['action'] ?? null) === 'auto' && isset($decision['price']) ? (float)$decision['price'] : null;
    }

    /** @return array{0: array{action: string, price: ?float}, 1: ?DeliveryQuote} */
    private function price(object $order, object $delivery, string $branchId): array
    {
        $quote = $this->quotes->quote($delivery, $branchId, $this->parcel->cart($order), forAdmin: true);

        return [OrderPriceRule::decide($order, $delivery, $quote?->price, $quote === null || $quote->price === null), $quote];
    }

    private function writePrice(int $orderId, float $price): void
    {
        $orders = $this->entityFactory->get(OrdersEntity::class);
        $orders->update($orderId, ['delivery_price' => $price]);
        $orders->updateTotalPrice($orderId);
        $this->snapshots->setPrice($orderId, $price, 'manager');
    }
}
