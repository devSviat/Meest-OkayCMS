<?php

namespace Okay\Modules\Sviat\Meest\Api;

enum ApiErrorKind: string
{
    case Unreachable = 'unreachable';
    /** Облікових даних у налаштуваннях немає: запит не надсилався, і Meest нічого не відмовляла. */
    case NotConfigured = 'not_configured';
    /** 401: token кабінету неправильний, відкликаний чи прострочений. */
    case BadToken = 'bad_token';
    case NotFound = 'not_found';
    /** Інше 4xx чи status: Error у 2xx: Meest зрозуміла запит і відмовила, текст у apiMessage. */
    case Rejected = 'rejected';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Malformed = 'malformed';
    case Empty = 'empty';
}
