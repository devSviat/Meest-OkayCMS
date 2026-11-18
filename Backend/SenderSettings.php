<?php

namespace Okay\Modules\Sviat\Meest\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\Meest\Parcels\MeestPhone;
use Okay\Modules\Sviat\Meest\Quote\ShippingConfig;

/** Блок «Відправник»: хто й звідки здає посилки. */
final class SenderSettings
{
    public const string NAME = ShippingConfig::SENDER_NAME;
    public const string PHONE = ShippingConfig::SENDER_PHONE;
    public const string CITY_ID = ShippingConfig::SENDER_CITY_ID;
    public const string BRANCH_ID = ShippingConfig::SENDER_BRANCH_ID;

    /** Межа `sender.name` у API Meest. */
    public const int NAME_MAX = 250;

    public function __construct(
        private readonly Settings $settings,
        private readonly SenderBranches $branches,
    ) {
    }

    /** @return array{name:string, phone:string, city_id:string, branch_id:string} */
    public function read(): array
    {
        $config = ShippingConfig::fromSettings($this->settings->get(...));

        return ['name' => $config->senderName, 'phone' => $config->senderPhone, 'city_id' => $config->senderCityId, 'branch_id' => $config->senderBranchId];
    }

    /**
     * Надіслане, у формі read(): форму з помилками показуємо з тим, що менеджер ввів, а не зі збереженим.
     *
     * @param array<string, mixed> $post
     * @return array{name:string, phone:string, city_id:string, branch_id:string}
     */
    public static function fromPost(array $post): array
    {
        return [
            'name' => self::text($post, 'sender_name'),
            'phone' => self::text($post, 'sender_phone'),
            'city_id' => strtolower(self::text($post, 'sender_city_id')),
            'branch_id' => strtolower(self::text($post, 'sender_branch_id')),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> поле форми → мовний ключ помилки; порожньо, якщо все гаразд
     */
    public function validate(array $post): array
    {
        $values = self::fromPost($post);
        $errors = [];
        $length = mb_strlen($values['name']);
        if ($length < 1 || $length > self::NAME_MAX) {
            $errors['sender_name'] = 'sviat__meest__sender_err_name';
        }
        if (MeestPhone::normalize($values['phone']) === null) {
            $errors['sender_phone'] = 'sviat__meest__sender_err_phone';
        }
        if (!$this->branches->cityExists($values['city_id'])) {
            $errors['sender_city_id'] = 'sviat__meest__sender_err_city';
        } elseif (!$this->branches->isSendable($values['city_id'], $values['branch_id'])) {
            $errors['sender_branch_id'] = 'sviat__meest__sender_err_branch';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> помилки з validate(); якщо вони є, нічого не зберігається
     */
    public function save(array $post): array
    {
        $errors = $this->validate($post);
        if ($errors !== []) {
            return $errors;
        }
        $values = self::fromPost($post);
        $this->settings->set(self::NAME, $values['name']);
        $this->settings->set(self::PHONE, MeestPhone::normalize($values['phone']));
        $this->settings->set(self::CITY_ID, $values['city_id']);
        $this->settings->set(self::BRANCH_ID, $values['branch_id']);

        return [];
    }

    /** @param array<string, mixed> $post */
    private static function text(array $post, string $field): string
    {
        return is_string($post[$field] ?? null) ? trim($post[$field]) : '';
    }
}
