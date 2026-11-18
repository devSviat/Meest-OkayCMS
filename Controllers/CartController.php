<?php

namespace Okay\Modules\Sviat\Meest\Controllers;

use Okay\Core\Cart;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\DeliveriesHelper;
use Okay\Helpers\UserHelper;
use Okay\Modules\Sviat\Meest\Cart\BranchList;
use Okay\Modules\Sviat\Meest\Cart\CitySearch;
use Okay\Modules\Sviat\Meest\Cart\CrossSiteRequest;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Cart\WorkSchedule;
use Okay\Modules\Sviat\Meest\Entities\BranchEntity;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use Okay\Modules\Sviat\Meest\Quote\DeliveryPriceView;

/**
 * Публічні endpoints кошика. Вони читають лише довідник Meest і власний кошик відвідувача:
 * жодних замовлень, жодних персональних даних. До Meest з них іде лише `calculate` для пункту з таблиці.
 */
class CartController
{
    public function cities(Request $request, Response $response, CitySearch $search, CartQuotes $quotes)
    {
        if (!$quotes->allowSearch()) {
            $response->setStatusCode(429);
            $this->json($response, ['cities' => [], 'problem' => 'busy']);

            return;
        }
        $query = self::typed($request);
        $this->json($response, ['cities' => is_string($query) ? $search->find($query) : []]);
    }

    public function branches(Request $request, Response $response, BranchList $branches, Cart $cart, UserHelper $userHelper, UahTotal $uahTotal, CartQuotes $quotes)
    {
        $cityId = self::uuid($request->get('city_id', null, null, false));
        if ($cityId === null) {
            $response->setStatusCode(400);
            $this->json($response, ['error' => 'city_id']);

            return;
        }
        if (!$quotes->allowSearch()) {
            $response->setStatusCode(429);
            $this->json($response, ['groups' => [], 'group' => null, 'points' => [], 'problem' => 'busy']);

            return;
        }
        $groupId = (int)$request->get('group_id', 'integer');
        $query = self::typed($request);
        $metrics = $uahTotal->metrics(self::visitorCart($cart, $userHelper));
        $this->json($response, $branches->forCity(
            $cityId,
            $metrics,
            $branches->declaredFor($metrics),
            $groupId > 0 ? $groupId : null,
            is_string($query) ? $query : '',
        ));
    }

    /**
     * Ціна й розклад обраного пункту (`ajax/meest/quote?delivery_id=&branch_id=`). `branch_id` спершу
     * звіряється з форматом і таблицею пунктів, і лише потім пишеться в сесію й іде в Meest. Числа способу
     * доставки беруться з власного списку кошика ядра, тож модулі, що його розширюють (безкоштовна доставка
     * промо), формують відповідь так само, як сторінку.
     */
    public function quote(Request $request, Response $response, CartQuotes $quotes, GroupsStore $groups, Cart $cart, UserHelper $userHelper, EntityFactory $entityFactory, DeliveriesHelper $deliveriesHelper, FrontTranslations $translations)
    {
        // Маршрут пише вибір у сесію: посилання з чужого сайту не повинне його змінювати.
        if (CrossSiteRequest::is($_SERVER)) {
            $response->setStatusCode(403);
            $this->json($response, ['error' => 'origin']);

            return;
        }
        $branchId = self::uuid($request->get('branch_id', null, null, false));
        $branch = $branchId === null ? null : $entityFactory->get(BranchEntity::class)->get($branchId);
        if (!$branch || (int)($branch->sending_only ?? 0) === 1 || !in_array((string)$branch->kind, BranchList::visibleKinds($groups->all()), true)) {
            $response->setStatusCode(400);
            $this->json($response, ['error' => 'branch_id']);

            return;
        }
        $deliveryId = (int)$request->get('delivery_id', 'integer');
        $delivery = $deliveryId > 0 ? $entityFactory->get(DeliveriesEntity::class)->get($deliveryId) : null;
        $cartData = self::visitorCart($cart, $userHelper);
        $quote = $delivery && !empty($delivery->enabled) ? $quotes->quote($delivery, (string)$branchId, $cartData) : null;
        if ($quote === null) {
            $response->setStatusCode(400);
            $this->json($response, ['error' => 'input']);

            return;
        }
        if ($quote->problem === 'busy') {
            $response->setStatusCode(429);
        }

        $answer = [
            'price' => null,
            'unpriced' => false,
            'priceText' => null,
            'totalWithDelivery' => null,
            'free' => false,
            'problem' => $quote->problem,
            'estimatedDate' => $quote->estimatedDate,
            'schedule' => WorkSchedule::groups((string)($branch->schedule_json ?? '')),
        ];
        $payments = $entityFactory->get(PaymentsEntity::class)->mappedBy('id')->find(['enabled' => 1]);
        $item = $deliveriesHelper->getCartDeliveriesList($cartData, $payments)[$deliveryId] ?? null;
        // Недоступність Meest не зберігається в сесії, тож список вище ще може містити ціну з адмінки.
        if ($item && $quote->unpriced()) {
            DeliveryPriceView::unpriced($item, (float)$cartData->total_price, (string)$translations->getTranslation('sviat__meest__cart_price_by_manager'));
        }
        if ($item && ($quote->price !== null || !empty($item->is_free_delivery) || $quote->unpriced())) {
            $answer['unpriced'] = $quote->unpriced() && empty($item->is_free_delivery);
            $answer['price'] = (float)$item->price;
            $answer['priceText'] = (string)$item->delivery_price_text;
            $answer['totalWithDelivery'] = (float)$item->total_price_with_delivery;
            $answer['free'] = !empty($item->is_free_delivery);
        }
        $this->json($response, $answer);
    }

    private function json(Response $response, array $data): void
    {
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    private static function uuid(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match(CartQuotes::UUID, $value) === 1 ? $value : null;
    }

    /**
     * Текст пошуку як введено. Request::get() типово кодує HTML, що перетворює "Кам'янець" на
     * "Кам&#039;янець" і нічого не знаходить; значення лише підставляється в запити й повертається як JSON.
     */
    private static function typed(Request $request): mixed
    {
        return $request->get('q', null, null, false);
    }

    /**
     * Кошик відвідувача так, як його бачить сторінка кошика. Ці endpoints оминають AbstractController ядра,
     * чий mergeCart(true) і завантажує кошик авторизованого покупця: Cart::init() лишає його порожнім.
     */
    private static function visitorCart(Cart $cart, UserHelper $userHelper): Cart
    {
        $userHelper->mergeCart(true);

        return $cart->get();
    }
}
