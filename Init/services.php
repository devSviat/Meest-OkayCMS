<?php

namespace Okay\Modules\Sviat\Meest;

use Okay\Core\Database;
use Okay\Core\BackendTranslations;
use Okay\Core\Config;
use Okay\Core\Cart;
use Okay\Core\FrontTranslations;
use Okay\Core\Design;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\EntityFactory;
use Okay\Core\Money;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\QueryFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Api\AuthFlags;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;
use Okay\Modules\Sviat\Meest\Api\Http\CurlTransport;
use Okay\Modules\Sviat\Meest\Api\Http\HttpTransport;
use Okay\Modules\Sviat\Meest\Api\MeestClient;
use Okay\Modules\Sviat\Meest\Api\RequestBudget;
use Okay\Modules\Sviat\Meest\Api\TokenProvider;
use Okay\Modules\Sviat\Meest\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\Meest\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Meest\Backend\KeySettings;
use Okay\Modules\Sviat\Meest\Backend\KeySettingsView;
use Okay\Modules\Sviat\Meest\Backend\SenderBranches;
use Okay\Modules\Sviat\Meest\Backend\SenderSettings;
use Okay\Modules\Sviat\Meest\Backend\ShippingSettings;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\Meest\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Meest\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Meest\Dictionaries\Store\DbDictionaryStore;
use Okay\Modules\Sviat\Meest\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\Meest\Cart\BranchList;
use Okay\Modules\Sviat\Meest\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Meest\Cart\CitySearch;
use Okay\Modules\Sviat\Meest\Cart\DictionaryReady;
use Okay\Modules\Sviat\Meest\Cart\PopularCities;
use Okay\Modules\Sviat\Meest\Cart\UahTotal;
use Okay\Modules\Sviat\Meest\Extenders\BackendExtender;
use Okay\Modules\Sviat\Meest\Extenders\DeliveryFilterExtender;
use Okay\Modules\Sviat\Meest\Extenders\FrontExtender;
use Okay\Modules\Sviat\Meest\Groups\GroupsStore;
use Okay\Modules\Sviat\Meest\Integration\MeestOrders;
use Okay\Modules\Sviat\Meest\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Meest\Orders\DbExistingOrders;
use Okay\Modules\Sviat\Meest\Orders\DbOrderTable;
use Okay\Modules\Sviat\Meest\Orders\OrderTable;
use Okay\Modules\Sviat\Meest\Orders\ExistingOrders;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Meest\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Meest\Orders\OrderParcel;
use Okay\Modules\Sviat\Meest\Orders\OrderPointChange;
use Okay\Modules\Sviat\Meest\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Meest\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Meest\Parcels\MassOrders;
use Okay\Modules\Sviat\Meest\Parcels\MassOrderSource;
use Okay\Modules\Sviat\Meest\Parcels\MassParcels;
use Okay\Modules\Sviat\Meest\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\Meest\Parcels\ParcelIssues;
use Okay\Modules\Sviat\Meest\Parcels\ParcelLookup;
use Okay\Modules\Sviat\Meest\Parcels\ParcelRows;
use Okay\Modules\Sviat\Meest\Parcels\ParcelService;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStore;
use Okay\Modules\Sviat\Meest\Parcels\ParcelTracking;
use Okay\Modules\Sviat\Meest\Parcels\ParcelView;
use Okay\Modules\Sviat\Meest\Parcels\Stickers;
use Okay\Modules\Sviat\Meest\Parcels\TrackingCursor;
use Okay\Modules\Sviat\Meest\Quote\CartQuotes;
use Okay\Modules\Sviat\Meest\Quote\ClientAddress;
use Okay\Modules\Sviat\Meest\Quote\QuoteService;
use Okay\Modules\Sviat\Meest\Storage\DbMutex;
use Okay\Modules\Sviat\Meest\Storage\ModuleState;
use Okay\Modules\Sviat\Meest\Storage\Mutex;
use Okay\Modules\Sviat\Meest\Storage\StateStorage;
use Psr\Log\LoggerInterface;

$now = static fn(): int => time();

return [
    ModuleState::class => [
        'class' => ModuleState::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    StateStorage::class => [
        'class' => ModuleState::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    DbMutex::class => [
        'class' => DbMutex::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    Mutex::class => [
        'class' => DbMutex::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    HttpTransport::class => [
        'class' => CurlTransport::class,
        'arguments' => [],
    ],
    CredentialsProvider::class => [
        'class' => CredentialsProvider::class,
        'arguments' => [new SR(Settings::class)],
    ],
    AuthFlags::class => [
        'class' => AuthFlags::class,
        'arguments' => [new SR(StateStorage::class)],
    ],
    TokenProvider::class => [
        'class' => TokenProvider::class,
        'arguments' => [new SR(CredentialsProvider::class), new SR(StateStorage::class), new SR(AuthFlags::class), $now],
    ],
    RequestBudget::class => [
        'class' => RequestBudget::class,
        'arguments' => [new SR(StateStorage::class), new SR(Mutex::class), $now],
    ],
    MeestClient::class => [
        'class' => MeestClient::class,
        'arguments' => [
            new SR(HttpTransport::class),
            new SR(TokenProvider::class),
            new SR(RequestBudget::class),
            new SR(CredentialsProvider::class),
            new SR(LoggerInterface::class),
        ],
    ],
    KeyChecker::class => [
        'class' => KeyChecker::class,
        'arguments' => [
            new SR(MeestClient::class),
            new SR(TokenProvider::class),
            new SR(CredentialsProvider::class),
            new SR(StateStorage::class),
            new SR(AuthFlags::class),
            $now,
        ],
    ],
    DbDictionaryStore::class => [
        'class' => DbDictionaryStore::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    DictionaryStore::class => [
        'class' => DbDictionaryStore::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    RegionSweep::class => [
        'class' => RegionSweep::class,
        'arguments' => [new SR(MeestClient::class), new SR(StateStorage::class)],
    ],
    DictionaryRunner::class => [
        'class' => DictionaryRunner::class,
        'arguments' => [
            new SR(RegionSweep::class),
            new SR(DictionaryStore::class),
            new SR(StateStorage::class),
            new SR(Mutex::class),
            new SR(CredentialsProvider::class),
            new SR(KeyChecker::class),
            $now,
        ],
    ],
    DictionaryCounts::class => [
        'class' => DictionaryCounts::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Database::class), new SR(QueryFactory::class)],
    ],
    KeySettings::class => [
        'class' => KeySettings::class,
        'arguments' => [
            new SR(Settings::class),
            new SR(TokenProvider::class),
            new SR(StateStorage::class),
            new SR(AuthFlags::class),
            new SR(KeyChecker::class),
        ],
    ],
    KeySettingsView::class => [
        'class' => KeySettingsView::class,
        'arguments' => [new SR(CredentialsProvider::class), new SR(KeyChecker::class), new SR(AuthFlags::class)],
    ],
    SenderBranches::class => [
        'class' => SenderBranches::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    SenderSettings::class => [
        'class' => SenderSettings::class,
        'arguments' => [new SR(Settings::class), new SR(SenderBranches::class)],
    ],
    ShippingSettings::class => [
        'class' => ShippingSettings::class,
        'arguments' => [new SR(Settings::class)],
    ],
    DictionaryStatusView::class => [
        'class' => DictionaryStatusView::class,
        'arguments' => [new SR(StateStorage::class), new SR(DictionaryCounts::class), $now],
    ],
    GroupsStore::class => [
        'class' => GroupsStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    PopularCities::class => [
        'class' => PopularCities::class,
        'arguments' => [new SR(StateStorage::class), new SR(EntityFactory::class)],
    ],
    OrderTable::class => [
        'class' => DbOrderTable::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class), new SR(Config::class)],
    ],
    DeliveryWarnings::class => [
        'class' => DeliveryWarnings::class,
        'arguments' => [new SR(StateStorage::class), new SR(OrderTable::class)],
    ],
    BackendExtender::class => [
        'class' => BackendExtender::class,
        'arguments' => [
            new SR(Design::class),
            new SR(Module::class),
            new SR(DeliveryWarnings::class),
            new SR(EntityFactory::class),
            new SR(BackendTranslations::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderRowsCleanup::class),
            new SR(OrderDeliveryLines::class),
            new SR(ParcelRows::class),
            new SR(ParcelView::class),
            new SR(Request::class),
            new SR(Response::class),
            new SR(OrdersMassAction::class),
            new SR(Modules::class),
        ],
    ],
    DeliveryFilterExtender::class => [
        'class' => DeliveryFilterExtender::class,
        'arguments' => [new SR(ParcelRows::class), new SR(BackendTranslations::class), new SR(Module::class), new SR(Modules::class)],
    ],
    CitySearch::class => [
        'class' => CitySearch::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    BranchList::class => [
        'class' => BranchList::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class), new SR(GroupsStore::class), new SR(Settings::class), $now],
    ],
    UahTotal::class => [
        'class' => UahTotal::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Money::class)],
    ],
    DictionaryReady::class => [
        'class' => DictionaryReady::class,
        'arguments' => [new SR(StateStorage::class), new SR(EntityFactory::class)],
    ],
    ClientAddress::class => [
        'class' => ClientAddress::class,
        'arguments' => [],
    ],
    QuoteService::class => [
        'class' => QuoteService::class,
        'arguments' => [new SR(MeestClient::class), new SR(AuthFlags::class), new SR(CredentialsProvider::class)],
    ],
    CartQuotes::class => [
        'class' => CartQuotes::class,
        'arguments' => [
            new SR(QuoteService::class),
            new SR(Settings::class),
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(Money::class),
            new SR(StateStorage::class),
            new SR(UahTotal::class),
            new SR(Mutex::class),
            new SR(ClientAddress::class),
            $now,
        ],
    ],
    CartSelectionValidator::class => [
        'class' => CartSelectionValidator::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(GroupsStore::class),
            new SR(DictionaryReady::class),
            new SR(Settings::class),
            $now,
        ],
    ],
    OrderDeliveryStore::class => [
        'class' => OrderDeliveryStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    OrderDeliveryLines::class => [
        'class' => OrderDeliveryLines::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Module::class), new SR(OrderDeliveryStore::class)],
    ],
    OrderParcel::class => [
        'class' => OrderParcel::class,
        'arguments' => [new SR(EntityFactory::class), new SR(UahTotal::class)],
    ],
    OrderPointChange::class => [
        'class' => OrderPointChange::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(GroupsStore::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderParcel::class),
            new SR(CartQuotes::class),
            new SR(OrderDeliveryLines::class),
            new SR(Settings::class),
            new SR(ParcelRows::class),
            $now,
        ],
    ],
    ExistingOrders::class => [
        'class' => DbExistingOrders::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    OrderRowsCleanup::class => [
        'class' => OrderRowsCleanup::class,
        'arguments' => [new SR(EntityFactory::class), new SR(StateStorage::class), new SR(ExistingOrders::class)],
    ],
    FrontExtender::class => [
        'class' => FrontExtender::class,
        'arguments' => [
            new SR(Design::class),
            new SR(Settings::class),
            new SR(Module::class),
            new SR(EntityFactory::class),
            new SR(FrontTranslations::class),
            new SR(CartSelectionValidator::class),
            new SR(OrderDeliveryStore::class),
            new SR(Cart::class),
            new SR(CartQuotes::class),
            new SR(Money::class),
            new SR(UahTotal::class),
            new SR(PopularCities::class),
            new SR(DictionaryReady::class),
            new SR(OrderDeliveryLines::class),
        ],
    ],
    ParcelStore::class => [
        'class' => ParcelStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    ParcelRows::class => [
        'class' => ParcelStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    ParcelIssues::class => [
        'class' => ParcelIssues::class,
        'arguments' => [],
    ],
    ParcelService::class => [
        'class' => ParcelService::class,
        'arguments' => [
            new SR(MeestClient::class),
            new SR(ParcelRows::class),
            new SR(CredentialsProvider::class),
            new SR(ParcelIssues::class),
            new SR(LoggerInterface::class),
            $now,
        ],
    ],
    ParcelDrafts::class => [
        'class' => ParcelDrafts::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(OrderDeliveryLines::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderParcel::class),
            new SR(UahTotal::class),
            new SR(Settings::class),
        ],
    ],
    ParcelLookup::class => [
        'class' => ParcelLookup::class,
        'arguments' => [
            new SR(MeestClient::class),
            new SR(ParcelRows::class),
            new SR(CredentialsProvider::class),
            new SR(ParcelIssues::class),
            new SR(LoggerInterface::class),
            $now,
        ],
    ],
    ParcelView::class => [
        'class' => ParcelView::class,
        'arguments' => [
            new SR(OrderDeliveryLines::class),
            new SR(OrderDeliveryStore::class),
            new SR(ParcelRows::class),
            new SR(ParcelDrafts::class),
            new SR(AuthFlags::class),
            $now,
        ],
    ],
    Stickers::class => [
        'class' => Stickers::class,
        'arguments' => [new SR(MeestClient::class), new SR(ParcelRows::class)],
    ],
    TrackingCursor::class => [
        'class' => TrackingCursor::class,
        'arguments' => [new SR(StateStorage::class), $now],
    ],
    ParcelTracking::class => [
        'class' => ParcelTracking::class,
        'arguments' => [
            new SR(MeestClient::class),
            new SR(ParcelRows::class),
            new SR(TrackingCursor::class),
            new SR(Mutex::class),
            new SR(AuthFlags::class),
            new SR(CredentialsProvider::class),
            new SR(StateStorage::class),
            new SR(ParcelIssues::class),
            new SR(LoggerInterface::class),
            $now,
        ],
    ],
    MassOrders::class => [
        'class' => MassOrderSource::class,
        'arguments' => [new SR(EntityFactory::class), new SR(OrderDeliveryLines::class), new SR(ParcelDrafts::class), new SR(Settings::class)],
    ],
    MassParcels::class => [
        'class' => MassParcels::class,
        'arguments' => [new SR(ParcelService::class), new SR(ParcelRows::class), new SR(MassOrders::class), new SR(LoggerInterface::class)],
    ],
    OrdersMassAction::class => [
        'class' => OrdersMassAction::class,
        'arguments' => [new SR(MassParcels::class), new SR(Stickers::class), new SR(LoggerInterface::class)],
    ],
    MeestOrders::class => [
        'class' => MeestOrders::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
];
