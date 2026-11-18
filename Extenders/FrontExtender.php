<?php

namespace Okay\Modules\Sviat\Meest\Extenders;

use Okay\Core\Cart;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Cart\CartSelection;
use Okay\Modules\Sviat\Meest\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Meest\Cart\DictionaryReady;
use Okay\Modules\Sviat\Meest\Cart\PopularCities;
use Okay\Modules\Sviat\Meest\Cart\ThemeClasses;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Cart\WorkSchedule;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Entities\CityEntity;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use Okay\Modules\Sviat\Meest\Quote\DeliveryPriceView;
use Okay\Modules\Sviat\Meest\Quote\OrderPrice;

class FrontExtender implements ExtensionInterface
{
    /** Тексти, потрібні скрипту кошика; ключі design/lang/*.php без префікса. */
    private const array SCRIPT_TEXTS = [
        'cart_no_results', 'cart_shown_of', 'cart_all_day', 'cart_weekdays', 'cart_day_off', 'cart_break', 'cart_schedule',
        'cart_too_heavy', 'cart_too_big', 'cart_over_insurance', 'cart_inbound', 'cart_overloaded', 'cart_estimated',
        'cart_no_points', 'cart_search_failed', 'cart_no_city', 'cart_busy',
        'cart_price_unreachable', 'cart_price_rejected', 'cart_calculating', 'cart_price_by_manager',
        'cart_price_after_point', 'cart_none_fit', 'cart_kind_branch', 'cart_kind_postomat',
        'err_city', 'err_branch',
    ];

    /** Маршрути кошика, які скрипт отримує в data-urls. */
    private const array ROUTES = [
        'cities' => 'Sviat_Meest_cart_cities',
        'branches' => 'Sviat_Meest_cart_branches',
        'quote' => 'Sviat_Meest_cart_quote',
    ];

    /** Вибір, перевірений getCartValidateError; зберігається, щойно замовлення створено (той самий запит). */
    private ?CartSelection $selection = null;

    /** Як розраховано ціну замовлення; зберігається від prepareDeliveryPriceInfo до знімка (той самий запит). */
    private ?array $pricing = null;

    public function __construct(
        private readonly Design $design,
        private readonly Settings $settings,
        private readonly Module $module,
        private readonly EntityFactory $entityFactory,
        private readonly FrontTranslations $translations,
        private readonly CartSelectionValidator $validator,
        private readonly OrderDeliveryStore $orders,
        private readonly Cart $cart,
        private readonly CartQuotes $quotes,
        private readonly Money $money,
        private readonly UahTotal $uahTotal,
        private readonly PopularCities $popularCities,
        private readonly DictionaryReady $dictionary,
        private readonly OrderDeliveryLines $orderLines,
    ) {
    }

    /**
     * ChainExtension для DeliveriesHelper::getCartDeliveriesList: спосіб доставки з ціною, розрахованою
     * для пункту відвідувача, показує цю ціну, також після того, як тема перезавантажить кошик через AJAX.
     */
    public function applyQuotes($deliveries, $cart)
    {
        if (!is_array($deliveries) || !is_object($cart)) {
            return $deliveries;
        }
        foreach ($deliveries as $delivery) {
            if (!is_object($delivery)) {
                continue;
            }
            // Уже безкоштовна (free_from або безкоштовна доставка акції раніше в ланцюжку): лишається як є.
            $quote = empty($delivery->is_free_delivery) ? $this->quotes->cachedForChoice($delivery, $cart) : null;
            $own = $this->quotes->isOwnMethod($delivery);
            $total = (float)$cart->total_price;
            switch (DeliveryPriceView::cartState($delivery, $quote, $own, !$own || $this->dictionary->ready(), $total)) {
                case 'price':
                    $this->showPrice($delivery, (float)$quote?->price, $total);
                    break;
                case 'unpriced':
                    DeliveryPriceView::unpriced($delivery, $total, (string)$this->translations->getTranslation('sviat__meest__cart_price_by_manager'));
                    break;
                case 'free':
                    DeliveryPriceView::free($delivery, $total, (string)$this->translations->getTranslation('cart_free'));
                    break;
                case 'awaiting':
                    DeliveryPriceView::awaiting($delivery, $total, (string)$this->translations->getTranslation('sviat__meest__cart_price_after_point'));
                    break;
            }
        }

        return $deliveries;
    }

    /**
     * ChainExtension для DeliveriesHelper::prepareDeliveryPriceInfo: ціна доставки замовлення береться з розрахунку
     * на сервері для пункту, який перевірив getCartValidateError у цьому ж запиті.
     */
    public function orderPrice($info, $delivery, $order)
    {
        if (!is_array($info) || !is_object($delivery) || !is_object($order) || !$this->quotes->isOwnMethod($delivery)) {
            return $info;
        }
        $branch = $this->selection?->branch;
        if ($branch === null) {
            return $info;
        }
        $quote = $this->quotes->quote($delivery, (string)$branch->id, $this->cart->get(), forOrder: true);
        $decision = OrderPrice::decide($quote, $info, $delivery, $order);
        $price = $decision['info']['delivery_price'] ?? null;
        $this->pricing = $quote === null ? null : OrderDeliveryStore::pricing($quote, is_numeric($price) ? (float)$price : null, $decision['source']);

        return $decision['info'];
    }

    /** ChainExtension для ValidateHelper::getCartValidateError: форма кошика перевіряється на сервері. */
    public function getCartValidateError($error, $order)
    {
        // Ланка контактів раніше в ланцюжку може зняти свою помилку і як '' (контракт ядра це дозволяє).
        if (($error !== null && $error !== '') || !is_object($order)) {
            return $error;
        }
        $result = $this->validator->validate($order, $_POST, $this->uahTotal->metrics($this->cart->get()));
        if (is_string($result)) {
            // Порожня помилка означала б, що перевірку пройдено, тож без перекладу лишається ключ.
            return $this->translations->getTranslation($result) ?: $result;
        }
        $this->selection = $result;

        return null;
    }

    /** QueueExtension для OrdersHelper::finalCreateOrderProcedure. */
    public function saveOrderDelivery($result, $order)
    {
        if (!is_object($order) || empty($order->id)) {
            return;
        }
        $selection = $this->selection ?? $this->validator->validate($order, $_POST, $this->uahTotal->metrics($this->cart->get()));
        if ($selection instanceof CartSelection) {
            $this->orders->save((int)$order->id, $selection, $this->pricing);
        }
    }

    /**
     * QueueExtension для OrdersHelper::getOrderPaymentMethodsList і NotifyHelper::finalEmailOrderUser/Admin:
     * сторінка замовлення й обидва листи показують збережений пункт. Для інших замовлень
     * присвоюється null, бо один запит може рендерити листи кількох замовлень з тим самим Design.
     */
    public function assignOrderDelivery($result, $order)
    {
        $this->design->assign('meest_order_delivery', is_object($order) ? $this->orderLines->forOrder($order) : null);
        $this->design->assign('meest_order_family', ThemeClasses::for((string)$this->settings->get('theme'))['family']);
    }

    /** QueueExtension для DeliveriesHelper::getCartDeliveriesList: дані для блоків кошика, лише для способів цього модуля. */
    public function getCartDeliveriesList($deliveries, $cart)
    {
        if (!self::preparesCart(Router::getCurrentRouteName())) {
            return;
        }
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);
        $own = array_filter((array)$deliveries, static fn($d): bool => is_object($d) && $moduleId > 0 && (int)($d->module_id ?? 0) === $moduleId);
        if ($own === []) {
            return;
        }

        $texts = [];
        foreach (self::SCRIPT_TEXTS as $key) {
            $texts[$key] = (string)$this->translations->getTranslation('sviat__meest__' . $key);
        }
        $urls = [];
        foreach (self::ROUTES as $name => $route) {
            $urls[$name] = (string)Router::generateUrl($route, [], true);
        }

        $this->design->assign('meest_module_id', $moduleId);
        $this->design->assign('meest_unavailable', !$this->dictionary->ready());
        $this->design->assign('meest_theme', ThemeClasses::for((string)$this->settings->get('theme')));
        $this->design->assign('meest_popular', $this->popularCities->buttons());
        // JSON будується тут: Smarty 5 не дозволяє PHP-функції на кшталт json_encode як модифікатори.
        $this->design->assign('meest_texts_json', json_encode($texts, JSON_UNESCAPED_UNICODE));
        $this->design->assign('meest_urls_json', json_encode($urls, JSON_UNESCAPED_SLASHES));
        $this->design->assign('meest_prefill', $this->prefill($own, is_object($cart) ? $cart : (object)[]));
    }

    /**
     * Маршрут ціни бере зі списку кошика ядра лише числа способу: дані шаблону кошика (prefill з останнім
     * замовленням, популярні міста) там нікому не потрібні, а це запити до бази на кожен вибір пункту.
     */
    public static function preparesCart(?string $routeName): bool
    {
        return $routeName !== self::ROUTES['quote'];
    }

    /**
     * Вибір, який покупець уже зробив; показується знову, коли сторінка кошика повертається з помилкою.
     *
     * @param array<object> $deliveries способи доставки цього модуля
     * @return array<int, array{city:?array, branch:?array, city_json:string, branch_json:string}>
     */
    private function prefill(array $deliveries, object $cart): array
    {
        // Без надісланої форми попереднє замовлення покупця знову пропонує свій пункт.
        $posted = isset($_POST[CartSelectionValidator::CITY_FIELD]) || isset($_POST[CartSelectionValidator::BRANCH_FIELD]);
        $user = $this->design->getVar('user');
        $last = !$posted && is_object($user) ? $this->orders->lastForUser((int)($user->id ?? 0)) : null;
        $metrics = $this->uahTotal->metrics($cart);
        $prefill = [];
        foreach ($deliveries as $delivery) {
            $deliveryId = (int)$delivery->id;
            [$cityId, $branchId] = CartSelectionValidator::postedIds($_POST, $deliveryId);
            $choice = self::prefillChoice($posted ? ['city_id' => $cityId, 'branch_id' => $branchId] : null, $this->sessionChoice($deliveryId), $last);
            $described = $this->describe($delivery, $metrics, (string)($choice['city_id'] ?? ''), (string)($choice['branch_id'] ?? ''));
            $prefill[$deliveryId] = $described + [
                'city_json' => $described['city'] ? (string)json_encode($described['city'], JSON_UNESCAPED_UNICODE) : '',
                'branch_json' => $described['branch'] ? (string)json_encode($described['branch'], JSON_UNESCAPED_UNICODE) : '',
            ];
        }

        return $prefill;
    }

    /**
     * Надіслана форма, інакше вибір, розрахований у цій сесії, інакше останнє замовлення.
     *
     * @param ?array{city_id:string, branch_id:string} $posted
     * @param array{city_id?:string, branch_id?:string} $session
     * @return array{city_id?:string, branch_id?:string}
     */
    public static function prefillChoice(?array $posted, array $session, ?object $last): array
    {
        if ($posted !== null) {
            return $posted;
        }
        if ($session !== []) {
            return $session;
        }

        return $last ? ['city_id' => (string)$last->city_id, 'branch_id' => (string)$last->branch_id] : [];
    }

    /** @return array{city_id?:string, branch_id?:string} */
    private function sessionChoice(int $deliveryId): array
    {
        $branchId = $this->quotes->chosenBranch($deliveryId);
        $branch = $branchId !== '' ? $this->entityFactory->get(BranchEntity::class)->get($branchId) : null;

        return $branch ? ['city_id' => (string)$branch->city_id, 'branch_id' => $branchId] : [];
    }

    /** @return array{city:?array, branch:?array} UUID лише з таблиць: id з форми чи старого замовлення шукаються, а не довіряються */
    private function describe(object $delivery, CartMetrics $cart, string $cityId, string $branchId): array
    {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        $city = preg_match($uuid, $cityId) === 1 ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $branch = $city && preg_match($uuid, $branchId) === 1 ? $this->entityFactory->get(BranchEntity::class)->get($branchId) : null;
        // Пункт, який замовлення зараз відхилило б, повертається до списку, де показано його проблему.
        $branch = $branch ? $this->validator->restorable($delivery, $city, $branch, $cart) : null;

        return [
            'city' => $city ? [
                'id' => (string)$city->id,
                'name' => (string)$city->name_ua,
                'type' => (string)($city->type_ua ?? ''),
                'district' => (string)($city->district_ua ?? ''),
                'region' => (string)($city->region_ua ?? ''),
            ] : null,
            'branch' => $branch ? [
                'id' => (string)$branch->id,
                'number' => (string)$branch->number,
                'kind' => (string)$branch->kind,
                'title' => (string)$branch->name_ua,
                'address' => (string)($branch->address_ua ?? ''),
                'schedule' => WorkSchedule::groups((string)($branch->schedule_json ?? '')),
            ] : null,
        ];
    }

    private function showPrice(object $delivery, float $price, float $cartTotal): void
    {
        $currencies = $this->entityFactory->get(CurrenciesEntity::class);
        $currency = $currencies->get((int)($_SESSION['currency_id'] ?? 0)) ?: $currencies->getMainCurrency();
        DeliveryPriceView::apply(
            $delivery,
            $price,
            $cartTotal,
            fn(float $amount): string => (string)$this->money->convert($amount),
            (string)($currency->sign ?? ''),
            (string)$this->translations->getTranslation('cart_paid_separate'),
            (string)$this->translations->getTranslation('cart_free'),
        );
    }
}
