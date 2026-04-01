<?php

declare(strict_types=1);

namespace OCA\Files_External_Gdrive\Settings;

use OCA\Files_External_Gdrive\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    public function __construct(
        private IConfig $config,
        private IL10N $l10n,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $params = [
            'client_id' => $this->config->getAppValue(Application::APP_ID, 'client_id', ''),
            'client_secret' => $this->config->getAppValue(Application::APP_ID, 'client_secret', ''),
        ];

        return new TemplateResponse(Application::APP_ID, 'admin', $params);
    }

    public function getSection(): string
    {
        return 'externalstorages';
    }

    public function getPriority(): int
    {
        return 50;
    }
}
