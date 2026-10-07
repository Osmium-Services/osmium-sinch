<?php

declare(strict_types=1);

namespace Osmium\Services\Sinch\Models;

/**
 * Sends SMS through Sinch's SMS REST API. A capability only: it never decides
 * when to text. Core or another service asks through the sms hooks.
 *
 * sms.providers - collect(); no payload. Returns this provider's descriptor:
 *            id, label, ready (enabled and credentials valid) and settingsRoute,
 *            so Shop settings can offer SMS only when it can actually send.
 *
 * sms.send - handle(); payload: to (string, any common phone format), body
 *            (string). Returns null when this service is off or not
 *            configured (so the caller can tell nothing handled it), else
 *            ['sent' => bool, 'detail' => string]. A number that cannot be
 *            made valid is a failed send, not an exception.
 *
 * Whether and when to text (for example only in production) is the caller's
 * decision, since sending costs money.
 */
class SinchSms
{
    private const SEND_URL = 'https://%s.sms.api.sinch.com/xms/v1/%s/batches';

    /**
     * @return array{id: string, label: string, ready: bool, settingsRoute: string}
     */
    public static function provider(): array
    {
        $config = SinchConfig::get();

        return [
            'id' => 'sinch',
            'label' => 'Sinch SMS',
            'ready' => ($config->enabled ?? false) && self::isConfigured($config),
            'settingsRoute' => 'admin/settings/sinch',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{sent: bool, detail: string}|null
     */
    public static function send(array $payload): ?array
    {
        $config = SinchConfig::get();
        $active = ($config->enabled ?? false) && self::isConfigured($config);
        if (!$active) return null;

        $to = self::toE164((string) ($payload['to'] ?? ''));
        if ($to === '') return ['sent' => false, 'detail' => 'error: not a valid phone number'];

        $result = self::deliver(config: $config, to: $to, body: (string) ($payload['body'] ?? ''));

        return ['sent' => \str_starts_with($result, 'ok'), 'detail' => $result];
    }

    /**
     * UK-focused: a leading 0 becomes +44 and a leading 00 becomes +. A number
     * already starting with + keeps its country code. Anything that does not
     * end up as valid E.164 returns '' so the caller skips it.
     */
    public static function toE164(string $phone): string
    {
        $trimmed = \trim($phone);
        $hasPlus = \str_starts_with($trimmed, '+');
        $digits = \preg_replace('/\D/', '', $trimmed) ?? '';
        if ($digits === '') return '';

        if ($hasPlus) $candidate = '+' . $digits;
        elseif (\str_starts_with($digits, '00')) $candidate = '+' . \substr($digits, 2);
        elseif (\str_starts_with($digits, '0')) $candidate = '+44' . \substr($digits, 1);
        elseif (\str_starts_with($digits, '44')) $candidate = '+' . $digits; // Typed with the code but no +
        else return '';

        return SinchConfig::isValidE164($candidate) ? $candidate : '';
    }

    /**
     * Posts one message to Sinch. Used by the hook and the settings page's
     * test button, which sends even while the service is switched off.
     *
     * Success is HTTP 200 or 201 with a batch id. Failures carry a JSON body
     * of {code, text} (400 and 403) or a plain 401 for a bad token.
     *
     * @return string "ok: <batch id>" or "error: <reason>"
     */
    public static function deliver(object $config, string $to, string $body): string
    {
        $url = \sprintf(self::SEND_URL, \rawurlencode((string) $config->region), \rawurlencode((string) $config->servicePlanId));

        $curl = \curl_init($url);
        \curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \json_encode(['from' => (string) $config->sender, 'to' => [$to], 'body' => $body]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config->apiToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = \curl_exec($curl);
        $status = (int) \curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = \curl_error($curl);

        if ($response === false) return "error: {$curlError}";

        $decoded = \json_decode((string) $response, associative: true);
        $decoded = \is_array($decoded) ? $decoded : [];

        $succeeded = $status === 200 || $status === 201;
        if ($succeeded) return 'ok: ' . (string) ($decoded['id'] ?? '');

        if ($status === 401) return 'error: HTTP 401 the token is not valid for this service plan - check the service plan ID, token and region';

        $code = (string) ($decoded['code'] ?? '');
        $text = (string) ($decoded['text'] ?? '');

        return "error: HTTP {$status}" . ($code !== '' ? " ({$code})" : '') . ($text !== '' ? " {$text}" : '');
    }

    public static function isConfigured(object $config): bool
    {
        return SinchConfig::isValidServicePlanId((string) ($config->servicePlanId ?? ''))
            && SinchConfig::isValidApiToken((string) ($config->apiToken ?? ''))
            && SinchConfig::isValidRegion((string) ($config->region ?? ''))
            && SinchConfig::isValidSender((string) ($config->sender ?? ''));
    }
}
