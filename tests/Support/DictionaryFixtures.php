<?php

namespace Modules\Sviat\Meest\Support;

/** Записи branchSearch і citySearch у формі схеми OpenAPI; назви, телефони й UUID вигадані. */
final class DictionaryFixtures
{
    public const string REGION = '11111111-0000-4000-8000-000000000001';

    /**
     * @param array<string, mixed> $override верхній рівень замінюється повністю, крім `branchLimits`: той зливається
     * @return array<string, mixed>
     */
    public static function branch(array $override = []): array
    {
        $limits = array_replace(self::limits(), $override['branchLimits'] ?? []);
        unset($override['branchLimits']);

        return array_replace([
            'branchID' => 'AAAAAAAA-0000-4000-8000-000000000001',
            'branchNo' => 9001,
            'branchNumber' => '01',
            'branchType' => 'ППВ',
            'isBranchOpen' => true,
            'isBranchClosed' => false,
            'branchTypeAPP' => '1',
            'ShortName' => 'Відділення №9001 Тестове, вул. Лісова 1',
            'FullName' => 'Відділення №9001 до 30 кг, Тестове, вул. Лісова 1',
            'ManagerReg' => 'Тестенко Т. Т.',
            'PhoneManagerReg' => '380000000001',
            'EMailManagerReg' => 'manager@example.invalid',
            'ManagerTer' => 'Тестенко Т. Т.',
            'PhoneManagerTer' => '380000000002',
            'EMailManagerTer' => 'territory@example.invalid',
            'cityID' => 'BBBBBBBB-0000-4000-8000-000000000001',
            'districtID' => 'CCCCCCCC-0000-4000-8000-000000000001',
            'regionID' => self::REGION,
            'addressDescr' => ['descrUA' => 'вул. Лісова', 'descrRU' => 'ул. Лесная', 'descrEN' => 'Lisova st.'],
            'building' => '1',
            'zipCode' => '00001',
            'latitude' => 50.1234567,
            'longitude' => 30.7654321,
            'workingHours' => 'Пн-Пт 09:00-18:00',
            'branchWorkTime' => [
                ['day' => 'Пн', 'timeFrom' => '09:00', 'timeTo' => '18:00', 'LunchBreakFrom' => '13:00', 'LunchBreakTo' => '14:00'],
                ['day' => 'Сб', 'timeFrom' => '10:00', 'timeTo' => '15:00', 'LunchBreakFrom' => '', 'LunchBreakTo' => ''],
            ],
            'branchLimits' => $limits,
            'overloaded' => false,
            'InboundRestriction' => false,
            'InboundRestrictionStartDate' => '',
            'InboundRestrictionEndDate' => '',
            'paymentMethods' => [['uid' => 'dddddddd-0000-4000-8000-000000000001', 'nameUkr' => 'Карткою онлайн']],
            'Services' => [['uid' => 'eeeeeeee-0000-4000-8000-000000000001', 'nameUkr' => 'Є генератор']],
            'ScheduleLoadDepartments' => [['Time_Range' => '13:00', 'Received_from_couriers' => 3]],
        ], $override);
    }

    /** Числа в реальних відповідях трапляються і числом, і рядком. */
    public static function limits(): array
    {
        return [
            'weightTotalMax' => '30',
            'volumeTotalMax' => 0.5,
            'insuranceTotalMax' => '15000',
            'weightPlaceMax' => 30,
            'volumePlaceMax' => '0.0800',
            'quantityPlacesMax' => 1,
            'gabaritesMax' => ['length' => '61', 'width' => 37, 'height' => '35', 'weightMax' => 30],
            'formatLimit' => false,
            'sendingOnly' => false,
            'receivingOnly' => false,
            'receiverCellPhoneRequired' => false,
            'terminalCash' => false,
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function city(array $override = []): array
    {
        return array_replace([
            'cityID' => 'BBBBBBBB-0000-4000-8000-000000000001',
            'viewCity' => 'Село',
            'cityKATUU' => '0000000000',
            'cityDescr' => ['descrUA' => 'Лісове', 'descrRU' => 'Лесное', 'descrEN' => 'Lisove'],
            'districtID' => 'CCCCCCCC-0000-4000-8000-000000000001',
            'districtDescr' => ['descrUA' => 'Північний', 'descrRU' => 'Северный', 'descrEN' => 'Pivnichnyi'],
            'regionID' => self::REGION,
            'regionDescr' => ['descrUA' => 'Тестова', 'descrRU' => 'Тестовая', 'descrEN' => 'Testova'],
            'isBranchInCity' => true,
            'latitude' => '50.1',
            'longitude' => '30.7',
        ], $override);
    }
}
