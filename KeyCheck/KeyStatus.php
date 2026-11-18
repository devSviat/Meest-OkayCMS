<?php

namespace Okay\Modules\Sviat\Meest\KeyCheck;

enum KeyStatus: string
{
    case Ok = 'ok';
    case BadToken = 'bad_token';
    /** contractID не UUID або Meest не знає такого договору. */
    case BadContract = 'bad_contract';
    case ContractDeleted = 'contract_deleted';
    case Unreachable = 'unreachable';
    case NotConfigured = 'not_configured';
    /** Перевірки не було або вона не дійшла до цього кроку (наприклад, договір не дивилися через відмову token). */
    case Unchecked = 'unchecked';
}
