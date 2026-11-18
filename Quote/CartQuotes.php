<?php

namespace Okay\Modules\Sviat\Meest\Quote;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Cart\CartMetrics;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Storage\Mutex;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Розрахунок ціни для того, що відвідувач має в кошику саме зараз. Усі вхідні дані читаються на сервері:
 * спосіб доставки, пункт видачі й кошик — з бази даних і сесії. Нічого з того, що тут оцінюється,
 * не береться з браузера, крім UUID пункту, який звіряється з таблицею.
 */
final class CartQuotes
{
    public const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string SESSION_SLOT = 'sviat__meest__quotes';
    private const string BUDGET_STATE = 'quote_budget';
    private const int RETRY_UNREACHABLE_AFTER = 60;
    private const int SEARCH_LIMIT = 60;

    /** Скільки секунд запит чекає, поки інший оновлює лічильник; запис займає мілісекунди. */
    private const int BUDGET_LOCK_WAIT = 2;

    public function __construct(
        private readonly QuoteService $service,
        private readonly Settings $settings,
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly Money $money,
        private readonly StateStorage $state,
        private readonly UahTotal $uahTotal,
        private readonly Mutex $mutex,
        private readonly ClientAddress $client,
        /** @var \Closure(): int */
        private readonly \Closure $now,
    ) {
    }

    /**
     * @param bool $forOrder оформлення замовлення: один виклик на замовлення, уже саме по собі обмеження — без лімітів відвідувача
     * @param bool $forAdmin менеджер рахує ціну замовлення: він авторизований, тож без лімітів вітрини; ціну запитують,
     *     навіть коли вітрина її приховує, а сесія менеджера не є кошиком відвідувача, тож кеш не читається й не пишеться
     * @return DeliveryQuote|null null, коли цей модуль не рахує ціну для цього способу, пункту чи кошика
     */
    public function quote(object $delivery, string $branchId, object $cart, bool $forOrder = false, bool $forAdmin = false): ?DeliveryQuote
    {
        if (!$this->isOwnMethod($delivery) || empty($cart->purchases) || preg_match(self::UUID, $branchId) !== 1) {
            return null;
        }
        $branch = $this->entityFactory->get(BranchEntity::class)->get($branchId);
        if (!$branch || (int)($branch->sending_only ?? 0) === 1) {
            return null;
        }

        $config = ShippingConfig::fromSettings($this->settings->get(...));
        if ($config->senderBranchId === '') {
            return new DeliveryQuote(null, null, 'none', 'no_sender');
        }

        $now = ($this->now)();
        $sendingDate = SendingDate::for($now, $config->cutoff);
        if ($forAdmin) {
            return $this->ask($config, $branchId, $this->uahTotal->metrics($cart), RequestBudget::OTHER, $sendingDate);
        }
        $bag = $this->bag();
        QuoteSession::choose($bag, (int)$delivery->id, $branchId);
        $key = QuoteKey::for((int)$delivery->id, $branchId, $cart, $sendingDate);
        $cached = QuoteSession::get($bag, $key, $now, $forOrder ? null : self::RETRY_UNREACHABLE_AFTER);
        if ($cached !== null) {
            $this->store($bag);

            return $cached;
        }

        if (!empty($delivery->hide_front_delivery_price) || FreeDelivery::applies($delivery, (float)($cart->total_price ?? 0))) {
            $this->store($bag);

            return new DeliveryQuote(null, null, 'none', null);
        }
        if (!$forOrder && !QuoteThrottle::allow($bag, $now)) {
            $this->store($bag);

            return new DeliveryQuote(null, null, 'fallback', 'busy');
        }
        if (!$forOrder && !$this->takeBudget($now)) {
            $this->store($bag);

            return new DeliveryQuote(null, null, 'fallback', 'busy');
        }

        $quote = $this->ask($config, $branchId, $this->uahTotal->metrics($cart), RequestBudget::CART, $sendingDate);
        // Зберігається й тоді, коли Meest недосяжна: оформлення бере те, що показав кошик, а не ціну хвилиною пізніше.
        QuoteSession::put($bag, $key, $quote, $now);
        $this->store($bag);

        return $quote;
    }

    private function ask(ShippingConfig $config, string $branchId, CartMetrics $metrics, string $slot, string $sendingDate): DeliveryQuote
    {
        $uah = $this->entityFactory->get(CurrenciesEntity::class)->findOne(['code' => 'UAH']);
        if (!$uah || $metrics->totalUah === null) {
            return new DeliveryQuote(null, null, 'fallback', 'no_uah');
        }

        return $this->inMainCurrency($this->service->quote(new QuoteRequest(
            $config,
            $branchId,
            Parcel::fromMetrics($metrics, $config),
            Parcel::insurance($config, $metrics->totalUah),
            $slot,
            $sendingDate,
        )), $uah);
    }

    /** Свіжий розрахунок для останнього вибору відвідувача в цьому способі й поточного кошика; ніколи не викликає Meest. */
    public function cachedForChoice(object $delivery, object $cart): ?DeliveryQuote
    {
        $bag = $this->bag();
        $chosen = QuoteSession::chosen($bag, (int)$delivery->id);
        if ($chosen === null || !$this->isOwnMethod($delivery)) {
            return null;
        }

        $now = ($this->now)();
        $sendingDate = SendingDate::for($now, ShippingConfig::fromSettings($this->settings->get(...))->cutoff);

        return QuoteSession::get($bag, QuoteKey::for((int)$delivery->id, $chosen, $cart, $sendingDate), $now);
    }

    /** Пункт, який відвідувач обрав для цього способу в цій сесії; '' — ще не обирав. */
    public function chosenBranch(int $deliveryId): string
    {
        return QuoteSession::chosen($this->bag(), $deliveryId) ?? '';
    }

    /** Пошук міст і пунктів публічний: сесія може зробити щонайбільше SEARCH_LIMIT запитів за вікно. */
    public function allowSearch(): bool
    {
        $bag = $this->bag();
        $allowed = QuoteThrottle::allow($bag, ($this->now)(), 'search', self::SEARCH_LIMIT);
        $this->store($bag);

        return $allowed;
    }

    public function isOwnMethod(object $delivery): bool
    {
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);

        return $moduleId > 0 && (int)($delivery->module_id ?? 0) === $moduleId;
    }

    /** Meest рахує в гривнях; ціни доставки в OkayCMS — в основній валюті магазину. */
    private function inMainCurrency(DeliveryQuote $quote, object $uah): DeliveryQuote
    {
        if ($quote->price === null) {
            return $quote;
        }
        // Точність 2 явно: за замовчуванням Money округлює до `cents` валюти UAH, а це може бути 0.
        $price = round((float)$this->money->convert($quote->price, (int)$uah->id, false, true, 2), 2);

        return new DeliveryQuote($price, $quote->estimatedDate, $quote->source, $quote->problem);
    }

    private function takeBudget(int $now): bool
    {
        // Читання й запис лічильника — під блокуванням: паралельні запити інакше проскакують ліміт.
        if (!$this->mutex->acquire(self::BUDGET_STATE, self::BUDGET_LOCK_WAIT)) {
            return false;
        }
        try {
            [$allowed, $state] = QuoteBudget::take($this->state->get(self::BUDGET_STATE), $now, QuoteBudget::LIMIT, QuoteBudget::visitor($this->client->address()), QuoteBudget::VISITOR_LIMIT);
            $this->state->set(self::BUDGET_STATE, $state);
        } finally {
            $this->mutex->release(self::BUDGET_STATE);
        }

        return $allowed;
    }

    private function bag(): array
    {
        return is_array($_SESSION[self::SESSION_SLOT] ?? null) ? $_SESSION[self::SESSION_SLOT] : [];
    }

    private function store(array $bag): void
    {
        $_SESSION[self::SESSION_SLOT] = $bag;
    }
}
