<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/** Налаштування, з яких складається тіло `POST parcel`: відправник, договір і шаблон примітки. */
final readonly class ParcelConfig
{
    public function __construct(
        public ShippingConfig $shipping,
        public string $senderName,
        public string $senderPhone,
        public string $senderBranchId,
        public string $contractId,
        public string $notationTemplate,
    ) {
    }

    /** @param callable(string): mixed $get Settings::get */
    public static function fromSettings(callable $get): self
    {
        $shipping = ShippingConfig::fromSettings($get);

        return new self(
            $shipping,
            $shipping->senderName,
            $shipping->senderPhone,
            $shipping->senderBranchId,
            Credentials::fromSettings($get)->contractId,
            $shipping->notation,
        );
    }
}
