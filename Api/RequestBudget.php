<?php

namespace Okay\Modules\Sviat\Meest\Api;

use Okay\Modules\Sviat\Meest\Storage\Mutex;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;

/**
 * Спільний для всього модуля лічильник запитів до Meest за хвилину (фіксоване вікно).
 * Meest дає 500 на хвилину на користувача; 100 лишаються кабінету й іншим інтеграціям того самого користувача.
 * Резерв не покриває сплеск на межі вікна: за дві сусідні секунди двох вікон можна витратити до 2 × LIMIT.
 */
final class RequestBudget
{
    public const int LIMIT = 400;
    /** Частка кошика: відвідувачі не можуть забрати весь ліміт у менеджера й планувальника. */
    public const int CART_LIMIT = 200;
    public const int WINDOW = 60;
    public const string CART = 'cart';
    public const string OTHER = 'other';

    private const string STATE = 'request_budget';
    private const string LOCK = 'budget';
    /** Без очікування паралельні запити одного вікна отримували б хибне RateLimited. */
    private const int LOCK_WAIT = 1;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly StateStorage $state,
        private readonly Mutex $mutex,
        private readonly \Closure $now,
    ) {
    }

    /** @throws MeestApiException RateLimited, коли ліміт вичерпано або блокування не отримано за секунду */
    public function take(string $slot): void
    {
        // Читання й запис лічильника — під блокуванням: інакше паралельні процеси проскакують ліміт.
        if (!$this->mutex->acquire(self::LOCK, self::LOCK_WAIT)) {
            throw MeestApiException::of(ApiErrorKind::RateLimited, 'Meest request budget: lock busy');
        }
        try {
            $allowed = $this->count($slot === self::CART, ($this->now)());
        } finally {
            $this->mutex->release(self::LOCK);
        }

        if (!$allowed) {
            throw MeestApiException::of(ApiErrorKind::RateLimited, "Meest request budget exhausted ({$slot})");
        }
    }

    private function count(bool $cart, int $now): bool
    {
        $window = $this->window($now);
        if ($window['all'] >= self::LIMIT || ($cart && $window['cart'] >= self::CART_LIMIT)) {
            return false;
        }
        $window['all']++;
        if ($cart) {
            $window['cart']++;
        }
        $this->state->set(self::STATE, json_encode($window));

        return true;
    }

    /** @return array{window_start: int, all: int, cart: int} */
    private function window(int $now): array
    {
        $saved = json_decode((string)$this->state->get(self::STATE), true);
        $fresh = ['window_start' => $now, 'all' => 0, 'cart' => 0];
        if (!is_array($saved) || !is_int($saved['window_start'] ?? null) || !is_int($saved['all'] ?? null) || !is_int($saved['cart'] ?? null)) {
            return $fresh;
        }
        // Початок вікна в майбутньому (годинник сервера відступив) теж відкриває нове вікно, а не блокує запити.
        if ($now - $saved['window_start'] >= self::WINDOW || $saved['window_start'] > $now) {
            return $fresh;
        }

        return ['window_start' => $saved['window_start'], 'all' => $saved['all'], 'cart' => $saved['cart']];
    }
}
