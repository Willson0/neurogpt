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
        if ($raw === '' || strlen($raw) > 8192) {
            throw new InvalidInitData('Malformed initData');
        }

        // Parsed by hand: parse_str() rewrites keys and builds arrays from "a[b]=c", which must never reach the signature check.
        $fields = [];
        foreach (explode('&', $raw) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode($key);
            if ($key === '' || array_key_exists($key, $fields)) {
                throw new InvalidInitData('Malformed initData');
            }
            $fields[$key] = urldecode($value);
        }

        $hash = $fields['hash'] ?? '';
        if (! preg_match('/^[0-9a-f]{64}$/i', $hash)) {
            throw new InvalidInitData('Missing hash');
        }
        unset($fields['hash']);

        ksort($fields, SORT_STRING);
        $pairs = [];
        foreach ($fields as $key => $value) {
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
        if (! is_array($user) || ! is_int($user['id'] ?? null) || $user['id'] <= 0 || ! empty($user['is_bot'])) {
            throw new InvalidInitData('No user in initData');
        }

        return [
            'user' => $user,
            'start_param' => $fields['start_param'] ?? null,
            'auth_date' => $authDate,
        ];
    }
}
