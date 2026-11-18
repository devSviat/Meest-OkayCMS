<?php

namespace Okay\Modules\Sviat\Meest\Extenders;

use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Meest\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderPointChange;
use Okay\Modules\Sviat\Meest\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Meest\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Meest\Parcels\ParcelCard;
use Okay\Modules\Sviat\Meest\Parcels\ParcelRows;
use Okay\Modules\Sviat\Meest\Parcels\ParcelView;
use Okay\Modules\Sviat\Meest\Parcels\PendingParcel;
use Okay\Modules\Sviat\Meest\Parcels\StickerFile;

class BackendExtender implements ExtensionInterface
{
    /** Тексти, які показує скрипт редактора пункту; ключі Backend/lang без префікса. */
    private const array EDITOR_TEXTS = [
        'order_city', 'order_point', 'order_estimated', 'order_price_fallback', 'op_city_placeholder', 'op_point_search', 'op_point_search_placeholder',
        'op_save', 'op_cancel', 'op_saving', 'op_change', 'op_no_points', 'op_choose_point', 'op_network',
        'op_unfit_too_heavy', 'op_unfit_too_big', 'op_unfit_over_insurance', 'op_unfit_inbound', 'op_overloaded', 'op_kind_branch', 'op_kind_postomat',
        'op_price_keep', 'op_price_same', 'op_price_free', 'op_price_show', 'op_price_auto', 'op_price_offer', 'op_price_apply', 'op_price_applied',
    ];

    public function __construct(
        private readonly Design $design,
        private readonly Module $module,
        private readonly DeliveryWarnings $warnings,
        private readonly EntityFactory $entityFactory,
        private readonly BackendTranslations $translations,
        private readonly OrderDeliveryStore $orders,
        private readonly OrderRowsCleanup $orderRows,
        private readonly OrderDeliveryLines $orderLines,
        private readonly ParcelRows $parcels,
        private readonly ParcelView $parcelView,
        private readonly Request $request,
        private readonly Response $response,
        private readonly OrdersMassAction $massAction,
        private readonly Modules $modules,
    ) {
    }

    /** QueueExtension для BackendDeliveriesHelper::getDelivery: попередження показуються лише на способах цього модуля. */
    public function deliveryWarning($delivery, $id = null): void
    {
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);
        $ours = is_object($delivery) && $moduleId > 0 && (int)($delivery->module_id ?? 0) === $moduleId;
        $this->design->assign('meest_delivery_warnings', $ours ? $this->warnings->for($delivery) : []);
    }

    /** QueueExtension для BackendOrdersHelper::delete (виконується до того, як ядро видалить замовлення). */
    public function deleteOrders($result, $ids): void
    {
        $this->orderRows->forOrders((array)$ids);
    }

    /** QueueExtension для BackendOrdersHelper::findOrder: сторінка замовлення показує й змінює пункт видачі та посилку. Лише читає. */
    public function assignOrderDelivery($order, $orderId = null)
    {
        $found = is_object($order) && !empty($order->id);
        $own = $found && $this->orderLines->ownDelivery($order) !== null;
        $this->design->assign('meest_order_delivery', $own ? $this->orderLines->forOrder($order) : null);
        $this->design->assign('meest_order_point', $own ? $this->pointEditor($order) : null);
        $this->design->assign('meest_parcel', $found && $this->enabled() ? $this->parcelView->forOrder($order) : null);
    }

    /** Те, що потрібно редактору пункту; збережений пункт іде лише з UUID, решту редактор питає сам. */
    private function pointEditor(object $order): array
    {
        $snapshot = $this->orders->forOrder((int)$order->id);
        $texts = [];
        foreach (self::EDITOR_TEXTS as $key) {
            $texts[$key] = (string)$this->translations->getTranslation('sviat__meest__' . $key);
        }

        return [
            'orderId' => (int)$order->id,
            'needsChoice' => $snapshot === null,
            'locked' => OrderPointChange::locks($this->parcels->forOrder((int)$order->id)),
            'cityId' => $snapshot ? (string)$snapshot->city_id : null,
            'cityName' => $snapshot ? (string)$snapshot->city_name : null,
            'branchId' => $snapshot ? (string)$snapshot->branch_id : null,
            'currency' => (string)($this->entityFactory->get(CurrenciesEntity::class)->getMainCurrency()->sign ?? ''),
            'textsJson' => json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];
    }

    /** ChainExtension для BackendOrdersHelper::buildFilter: дія списку (ядро виконує свої дії безпосередньо перед цим). */
    public function listFilter($filter)
    {
        if (is_array($filter) && $this->enabled()) {
            $this->handleMassAction();
        }

        return $filter;
    }

    private function handleMassAction(): void
    {
        if ($this->request->method('post')) {
            $result = $this->massAction->handleRequest(fn(): bool => (bool)$this->request->checkSession(), $_SESSION);
            if ($result instanceof StickerFile) {
                $this->sendFile($result);
            }
            if ($result === true) {
                Response::redirectTo($this->request->url());
            }

            return;
        }
        $taken = OrdersMassAction::take($_SESSION);
        $this->design->assign('meest_mass_result', $taken === null ? null : OrdersMassAction::view(
            $taken,
            fn(string $key): string => (string)$this->translations->getTranslation($key),
        ));
    }

    /** Хук фільтра виконується посеред контролера списку: файл іде одразу, решта сторінки не рендериться. */
    private function sendFile(StickerFile $file): never
    {
        $this->response->setStatusCode(200)
            ->addHeader('Content-Type: ' . $file->type)
            ->addHeader('Content-Disposition: attachment; filename="' . $file->name . '"')
            ->addHeader('Cache-Control: no-store')
            ->addHeader('X-Content-Type-Options: nosniff')
            ->setContent($file->bytes, RESPONSE_TEXT)
            ->sendContent();
        exit;
    }

    /** QueueExtension для BackendOrdersHelper::findOrders: бейджі сторінки з бази одним запитом, без Meest. */
    public function listOrders($orders, $filter = [])
    {
        if (!is_array($orders) || $orders === [] || !$this->enabled()) {
            return;
        }
        $rows = $this->parcels->forOrders(array_map(static fn($order): int => (int)$order->id, array_values($orders)));
        foreach ($orders as $order) {
            $row = $rows[(int)$order->id] ?? null;
            if ($row !== null) {
                $order->meest_list_parcel = self::listParcel($row);
            }
        }
    }

    /** @return array{barcode: ?string, group: ?string, statusKey: ?string, eventName: ?string} */
    public static function listParcel(object $row): array
    {
        // Резерв чи невідомий результат: штрихкоду ще немає, але менеджер має побачити, що замовлення зайняте.
        if (PendingParcel::is($row)) {
            return ['barcode' => null, 'group' => 'unsettled', 'statusKey' => 'sviat__meest__list_unsettled', 'eventName' => null];
        }
        $card = ParcelCard::fromRow($row);

        return [
            'barcode' => $card['barcode'],
            'group' => $card['statusGroup'],
            'statusKey' => $card['statusKey'],
            'eventName' => $card['event']['name'] ?? null,
        ];
    }

    /** Адмінка запускає init() і вимкнених модулів: ланки списку зареєстровані, тож вимкнений модуль мусить мовчати сам. */
    private function enabled(): bool
    {
        return (bool)$this->modules->isActiveModule('Sviat', 'Meest');
    }
}
