<?php

namespace Okay\Modules\Sviat\Meest\Cart;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Settings;
use Okay\Entities\DeliveriesEntity;
use Okay\Modules\Sviat\Meest\Dictionaries\Rows\BranchKind;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Parcels\MeestPhone;
use Okay\Modules\Sviat\Meest\Quote\Parcel;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/**
 * Перевіряє на сервері те, що каже форма кошика: браузер може надіслати будь-які id. Формі
 * не довіряємо ні в чому, крім двох UUID, і лише для способів доставки цього модуля.
 */
final class CartSelectionValidator
{
    public const string CITY_FIELD = 'meest_city_id';
    public const string BRANCH_FIELD = 'meest_branch_id';
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const array FIT_ERRORS = [
        FitProblem::TooHeavy->value => 'sviat__meest__err_too_heavy',
        FitProblem::TooBig->value => 'sviat__meest__err_too_big',
        FitProblem::OverInsurance->value => 'sviat__meest__err_insurance',
        FitProblem::Inbound->value => 'sviat__meest__err_inbound',
    ];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly GroupsStore $groups,
        private readonly DictionaryReady $dictionary,
        private readonly Settings $settings,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param CartMetrics $cart метрики з сумою в гривнях (UahTotal)
     * @return CartSelection|string вибір або мовний ключ помилки, яку треба показати
     */
    public function validate(object $order, array $post, CartMetrics $cart): CartSelection|string
    {
        $deliveryId = (int)($order->delivery_id ?? 0);
        $delivery = $deliveryId > 0 ? $this->entityFactory->get(DeliveriesEntity::class)->get($deliveryId) : null;
        $moduleId = $this->moduleId();
        if (!$delivery || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return CartSelection::none();
        }
        [$cityId, $branchId] = self::postedIds($post, $deliveryId);
        $city = $cityId !== '' ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $branch = $branchId !== '' ? $this->entityFactory->get(BranchEntity::class)->get($branchId) : null;
        $config = ShippingConfig::fromSettings($this->settings->get(...));

        return self::check(
            $delivery,
            $moduleId,
            $city ?: null,
            $branch ?: null,
            $cart,
            $config,
            Parcel::insurance($config, $cart->totalUah ?? $cart->total),
            BranchList::today(($this->clock)()),
            $this->groups->all(),
            $this->dictionary->ready(),
            (string)($order->phone ?? ''),
        );
    }

    /**
     * Пункт, який відновлює кошик (надіслана форма, сесія, останнє замовлення), або null, коли
     * оформлення замовлення зараз його відхилило б. Телефон ще не відомий: його перевіряє оформлення.
     */
    public function restorable(object $delivery, ?object $city, ?object $branch, CartMetrics $cart): ?object
    {
        if ($branch === null) {
            return null;
        }
        $config = ShippingConfig::fromSettings($this->settings->get(...));
        $result = self::check(
            $delivery,
            $this->moduleId(),
            $city,
            $branch,
            $cart,
            $config,
            Parcel::insurance($config, $cart->totalUah ?? $cart->total),
            BranchList::today(($this->clock)()),
            $this->groups->all(),
        );

        return $result instanceof CartSelection ? $branch : null;
    }

    /**
     * @param float $declared оголошена цінність, грн
     * @param list<object> $groupRows рядки GroupEntity
     * @param ?string $phone телефон замовлення; null — ще не відомий
     */
    public static function check(
        ?object $delivery,
        int $moduleId,
        ?object $city,
        ?object $branch,
        CartMetrics $cart,
        ShippingConfig $box,
        float $declared,
        string $today,
        array $groupRows,
        bool $dictionaryReady = true,
        ?string $phone = null,
    ): CartSelection|string {
        if ($delivery === null || $moduleId === 0 || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return CartSelection::none();
        }
        if (!$dictionaryReady) {
            return 'sviat__meest__err_dictionary';
        }
        if ($city === null) {
            return 'sviat__meest__err_city';
        }
        if ($branch === null || BranchKind::tryFrom((string)($branch->kind ?? '')) === null) {
            return 'sviat__meest__err_branch';
        }
        if ((string)$branch->city_id !== (string)$city->id) {
            return 'sviat__meest__err_branch_city';
        }
        // Кошик таких пунктів не показує, тож і форма не може їх обрати.
        if ((int)($branch->sending_only ?? 0) === 1 || !in_array((string)$branch->kind, BranchList::visibleKinds($groupRows), true)) {
            return 'sviat__meest__err_branch_hidden';
        }
        $problems = BranchFit::problems($cart, $box, $declared, $branch, $today);
        if ($problems !== []) {
            return self::FIT_ERRORS[$problems[0]->value];
        }
        if ($phone !== null && (int)($branch->phone_required ?? 0) === 1) {
            $normalized = MeestPhone::normalize($phone);
            if ($normalized === null || !MeestPhone::isMobile($normalized)) {
                return 'sviat__meest__err_phone_mobile';
            }
        }

        return new CartSelection($delivery, $city, $branch);
    }

    /** @return array{0:string, 1:string} UUID міста й пункту, надіслані для цього способу доставки; '' — немає */
    public static function postedIds(array $post, int $deliveryId): array
    {
        $read = static function (string $field) use ($post, $deliveryId): string {
            $value = is_array($post[$field] ?? null) ? ($post[$field][$deliveryId] ?? null) : null;
            $value = is_string($value) ? strtolower(trim($value)) : '';

            return preg_match(self::UUID, $value) === 1 ? $value : '';
        };

        return [$read(self::CITY_FIELD), $read(self::BRANCH_FIELD)];
    }

    private function moduleId(): int
    {
        return (int)$this->module->getModuleIdByNamespace(__CLASS__);
    }
}
