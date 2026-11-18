<?php

namespace Modules\Sviat\Meest\Support;

/** Відповіді contractClientInfo з вигаданими назвою, ЄДРПОУ та реквізитами. */
final class ContractFixtures
{
    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function body(array $override = []): array
    {
        return array_replace([
            'contractID' => '0a1b2c3d-0000-4000-8000-00000000000a',
            'contract' => 'договір 0001',
            'number' => 'TS-000001',
            'edrpou' => '00000001',
            'date' => '01.02.2023',
            'dateTo' => '31.03.2027',
            'deleted' => false,
            'client' => 'Тестовий',
            'clientFullName' => 'ТОВ Тестовий Відправник',
            'phone' => '380000000000',
            'eMail' => 'sender@example.invalid',
            'client_address' => 'вул. Вигадана, 1',
            'isAvailableCash' => true,
            'isAvailableNoncash' => false,
            'IBAN' => 'UA000000000000000000000000000',
            'bankName' => 'Тестовий банк',
            'MFO' => '000000',
        ], $override);
    }
}
