<?php

declare(strict_types=1);

namespace OCA\Files_External_Gdrive\Controller;

use OCA\Files_External_Gdrive\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Save the Google OAuth2 client credentials (admin only).
     */
    public function saveCredentials(string $client_id, string $client_secret): DataResponse
    {
        $this->config->setAppValue(Application::APP_ID, 'client_id', $client_id);
        $this->config->setAppValue(Application::APP_ID, 'client_secret', $client_secret);

        return new DataResponse(['status' => 'success']);
    }

    /**
     * Return the stored client_id (for the frontend OAuth flow).
     *
     * @NoAdminRequired
     */
    public function getClientId(): DataResponse
    {
        $clientId = $this->config->getAppValue(Application::APP_ID, 'client_id', '');

        if ($clientId === '') {
            return new DataResponse(
                ['status' => 'error', 'message' => 'Google Drive client not configured'],
                Http::STATUS_PRECONDITION_FAILED,
            );
        }

        return new DataResponse([
            'status' => 'success',
            'client_id' => $clientId,
        ]);
    }
}
