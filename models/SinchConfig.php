<?php

declare(strict_types=1);

namespace Osmium\Services\Sinch\Models;

/**
 * Sinch SMS configuration helper.
 *
 * Loads this service's own settings file, matching the file-based config
 * convention used by the other services (app/config/services/{id}.json.php).
 */
class SinchConfig
{
    /** Regional hosts documented for the SMS REST API: id => label. */
    public const REGIONS = [
        'eu' => 'Europe',
        'us' => 'United States',
        'au' => 'Australia',
        'br' => 'Brazil',
        'ca' => 'Canada',
    ];
    public const DEFAULT_REGION = 'eu';

    private static ?object $config = null;
    private static string $configPath = 'app/config/services/sinch.json.php';

    /**
     * Falls back to defaults (disabled, no credentials) if the config file is
     * missing, so installing this service stays inert until someone visits
     * its settings page and saves credentials.
     */
    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = (string) \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));

        self::$config = (object) ((array) ($decoded->sinch ?? []) + (array) self::defaults());

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    public static function isValidRegion(string $region): bool
    {
        return isset(self::REGIONS[$region]);
    }

    /**
     * Loose shape checks only: Sinch's exact service plan ID and token
     * formats are not documented on the pages checked. The plan ID goes into
     * the request URL, so it is limited to URL-safe characters; the token goes
     * into a header, so it cannot hold whitespace.
     */
    public static function isValidServicePlanId(string $id): bool
    {
        return \preg_match(pattern: '/^[A-Za-z0-9_-]{4,64}$/', subject: $id) === 1;
    }

    public static function isValidApiToken(string $token): bool
    {
        return \preg_match(pattern: '/^\S{8,128}$/', subject: $token) === 1;
    }

    /**
     * Sender is an E.164 number or an alphanumeric sender ID (up to 11
     * letters, digits or spaces, with at least one letter). Sinch's docs call
     * the length "typically 3 to 11"; only the upper bound is enforced here.
     */
    public static function isValidSender(string $sender): bool
    {
        return self::isValidE164($sender)
            || \preg_match(pattern: '/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/', subject: $sender) === 1;
    }

    public static function isValidE164(string $number): bool
    {
        return \preg_match(pattern: '/^\+[1-9]\d{6,14}$/', subject: $number) === 1;
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'servicePlanId' => '',
            'apiToken' => '',
            'region' => self::DEFAULT_REGION,
            'sender' => '',
        ];
    }
}
