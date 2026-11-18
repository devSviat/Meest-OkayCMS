<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\CredentialsProvider;

final class FixedCredentials extends CredentialsProvider
{
    public function __construct(private readonly Credentials $credentials)
    {
    }

    public function get(): Credentials
    {
        return $this->credentials;
    }
}
