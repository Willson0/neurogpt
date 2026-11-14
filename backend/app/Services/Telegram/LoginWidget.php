<?php

namespace App\Services\Telegram;

use App\Exceptions\InvalidInitData;

/**
 * Validates what the Telegram Login Widget returns in a browser, following
 * https://core.telegram.org/widgets/login#checking-authorization
 * The client sends it as "Authorization: tgl <base64 of the JSON object>".
 */
class LoginWidget
{
    private const FIELDS = ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date'];

    /**
     * @return array{user: array, start_param: null, auth_date: int}
     *
     * @throws InvalidInitData
     */
    public static function validate(string $raw): array
    {
        $token = (string) config('neurogpt.telegram.bot_token');
        if ($token === '') {
            throw new InvalidInitData('Bot token is not configured');
        }
        $data = strlen($raw) <= 4096 ? json_decode((string) base64_decode($raw, true), true) : null;
        $hash = is_array($data) ? ($data['hash'] ?? '') : '';
        if (! is_string($hash) || ! preg_match('/^[0-9a-f]{64}$/i', $hash)) {
            throw new InvalidInitData('Malformed login data');
        }

        $pairs = [];
        foreach (self::FIELDS as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $pairs[$key] = $key.'='.$data[$key];
            }
        }
        ksort($pairs, SORT_STRING);

        $expected = hash_hmac('sha256', implode("\n", $pairs), hash('sha256', $token, true));
        if (! hash_equals($expected, strtolower($hash))) {
            throw new InvalidInitData('Signature mismatch');
        }

        $authDate = (int) ($data['auth_date'] ?? 0);
        if ($authDate <= 0 || time() - $authDate > (int) config('neurogpt.telegram.login_ttl') || $authDate - time() > 300) {
            throw new InvalidInitData('Login expired');
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidInitData('No user in login data');
        }

        return [
            'user' => ['id' => $id] + array_intersect_key($data, array_flip(['first_name', 'last_name', 'username', 'photo_url'])),
            'start_param' => null,
            'auth_date' => $authDate,
        ];
    }
}
