<?php

declare(strict_types=1);

namespace Osmium\Services\Sinch\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Sinch\Models\SinchConfig;
use Osmium\Services\Sinch\Models\SinchSms;

/**
 * Sinch SMS settings controller - a full-page form POST/redirect flow,
 * matching the Meta Pixel service.
 *
 * Routes:
 *   - index() → /admin/settings/sinch/  (GET shows the form, POST saves it
 *     or, with action=send_test, sends a test text with the saved settings)
 */
class SinchController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/sinch.json.php';
    private const DEFAULT_CONFIG = <<<'JSON'
        <?php exit(); ?>
        {
            "sinch": {
                "enabled": false,
                "servicePlanId": "",
                "apiToken": "",
                "region": "eu",
                "sender": ""
            }
        }
        JSON;

    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handleSubmit();

        $config = (array) SinchConfig::get();
        $config['apiTokenSet'] = ($config['apiToken'] ?? '') !== '';
        unset($config['apiToken']); // The saved token never reaches the page
        $this->data['admin']['config']['sinch'] = $config;
        $this->data['admin']['settingsSaved'] = $_SESSION['sinch_settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['sinch_settings_error'] ?? false;
        $this->data['admin']['testResult'] = $_SESSION['sinch_test_result'] ?? false;
        unset($_SESSION['sinch_settings_saved'], $_SESSION['sinch_settings_error'], $_SESSION['sinch_test_result']);

        $this->setView('sinch/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) {
            $_SESSION['sinch_settings_error'] = 'Invalid form submission. Please try again.';
            $this->redirect('settings/sinch/');
        }

        $isTest = ($_POST['action'] ?? '') === 'send_test';
        if ($isTest) $this->handleTest();

        $enabled = isset($_POST['enabled']);
        $servicePlanId = \trim($_POST['service_plan_id'] ?? '');
        $region = \trim($_POST['region'] ?? SinchConfig::DEFAULT_REGION);
        $sender = \trim($_POST['sender'] ?? '');

        $postedToken = \trim($_POST['api_token'] ?? '');
        $apiToken = $postedToken === '' ? (string) (SinchConfig::get()->apiToken ?? '') : $postedToken; // Blank keeps the stored token

        $error = $this->validate(
            enabled: $enabled,
            servicePlanId: $servicePlanId,
            apiToken: $apiToken,
            region: $region,
            sender: $sender,
        );
        if ($error !== null) {
            $_SESSION['sinch_settings_error'] = $error;
            $this->redirect('settings/sinch/');
        }

        $this->saveConfig(
            enabled: $enabled,
            servicePlanId: $servicePlanId,
            apiToken: $apiToken,
            region: $region,
            sender: $sender,
        );

        $this->admin->model->changelog->log(
            description: 'Updated Sinch SMS settings',
            recordType: 'settings',
        );

        SinchConfig::clearCache();

        $_SESSION['sinch_settings_saved'] = true;
        $this->redirect('settings/sinch/');
    }

    private function validate(bool $enabled, string $servicePlanId, string $apiToken, string $region, string $sender): ?string
    {
        $hasAnyCredential = $servicePlanId !== '' || $apiToken !== '' || $sender !== '';
        $needsValidCredentials = $enabled || $hasAnyCredential;

        if ($needsValidCredentials && !SinchConfig::isValidServicePlanId($servicePlanId)) {
            return 'Service plan ID looks wrong: paste just the ID from the Sinch dashboard (letters, digits, hyphens or underscores).';
        }
        if ($needsValidCredentials && !SinchConfig::isValidApiToken($apiToken)) {
            return 'API token looks wrong: paste just the token from the Sinch dashboard, with no spaces.';
        }
        if (!SinchConfig::isValidRegion($region)) {
            return 'Choose one of the listed regions.';
        }
        if ($needsValidCredentials && !SinchConfig::isValidSender($sender)) {
            return 'Sender must be a number like +447700900123, an alphanumeric sender ID (up to 11 characters, at least one letter).';
        }

        return null;
    }

    /** Sends a test text using the saved settings, so what is tested is what is live. */
    private function handleTest(): void
    {
        $config = SinchConfig::get();
        $to = SinchSms::toE164(\trim($_POST['test_number'] ?? ''));

        if (!SinchSms::isConfigured($config)) {
            $_SESSION['sinch_settings_error'] = 'Save valid credentials and a sender first, then send a test.';
        } elseif ($to === '') {
            $_SESSION['sinch_settings_error'] = 'Enter a mobile number to text, such as 07700 900123 or +447700900123.';
        } else {
            $_SESSION['sinch_test_result'] = SinchSms::deliver(config: $config, to: $to, body: 'This is a test text from your Osmium site.');
        }

        $this->redirect('settings/sinch/');
    }

    private function saveConfig(
        bool $enabled,
        string $servicePlanId,
        string $apiToken,
        string $region,
        string $sender,
    ): void
    {
        $configExists = \file_exists(self::CONFIG_FILE_PATH);
        if (!$configExists) $this->ensureConfigDirectoryExists();

        $content = $configExists ? \file_get_contents(self::CONFIG_FILE_PATH) : self::DEFAULT_CONFIG;

        $jsonStart = \strpos(haystack: $content, needle: '{');
        $phpHeader = \substr(string: $content, offset: 0, length: $jsonStart);
        $data = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true) ?? [];

        $data['sinch'] = [
            'enabled' => $enabled,
            'servicePlanId' => $servicePlanId,
            'apiToken' => $apiToken,
            'region' => $region,
            'sender' => $sender,
        ];

        $newJson = \json_encode(
            value: $data,
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, $phpHeader . $newJson . "\n");
    }

    private function ensureConfigDirectoryExists(): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        $alreadyExists = \is_dir($dir);
        if (!$alreadyExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);
    }

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
