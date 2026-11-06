<?php

namespace App\Services\Telegram;

use App\Exceptions\InvalidInitData;

/**
 * Validates the initData string a Telegram Mini App sends, following
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class InitData
{
    public function __construct(
        private readonly ?string $botToken,
        private readonly int $ttl = 86400,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('neurogpt.telegram.bot_token'), config('neurogpt.telegram.init_data_ttl'));
    }

    /**
     * @return array{user: array, start_param: ?string, auth_date: int}
     *
     * @throws InvalidInitData
     */
    public function validate(string $raw): array
    {
        if (! $this->botToken) {
            throw new InvalidInitData('Bot token is not configured');
        }

        parse_str($raw, $fields);
        $hash = $fields['hash'] ?? null;
        if (! is_string($hash) || $hash === '') {
            throw new InvalidInitData('Missing hash');
        }
        unset($fields['hash']);

        ksort($fields);
        $pairs = [];
        foreach ($fields as $key => $value) {
            if (! is_string($value)) {
                throw new InvalidInitData('Malformed field '.$key);
            }
            $pairs[] = $key.'='.$value;
        }

        $secret = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', implode("\n", $pairs), $secret);
        if (! hash_equals($expected, strtolower($hash))) {
            throw new InvalidInitData('Signature mismatch');
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);
        if ($authDate <= 0 || time() - $authDate > $this->ttl || $authDate - time() > 300) {
            throw new InvalidInitData('initData expired');
        }

        $user = json_decode($fields['user'] ?? '', true);
        if (! is_array($user) || empty($user['id'])) {
            throw new InvalidInitData('No user in initData');
        }

        return [
            'user' => $user,
            'start_param' => $fields['start_param'] ?? null,
            'auth_date' => $authDate,
        ];
    }
}
