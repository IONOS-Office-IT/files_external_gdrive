<?php

declare(strict_types=1);

namespace OCA\Files_External_Gdrive\Controller;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use OCA\Files_External\Service\UserStoragesService;
use OCA\Files_External\Lib\StorageConfig;
use OCA\Files_External_Gdrive\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class OauthController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Handle the OAuth2 token exchange with Google.
     *
     * @NoAdminRequired
     */
    public function receiveToken(
        ?string $redirect,
        ?int $step,
        ?string $code,
    ): DataResponse {
        $clientId = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
        $clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');

        if ($clientId === '' || $clientSecret === '' || $redirect === null || $step === null) {
            return new DataResponse(
                ['status' => 'error', 'data' => ['message' => 'Google Drive not configured by admin']],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirect);
        $client->setScopes([GoogleDriveService::DRIVE]);
        $client->setApprovalPrompt('force');
        $client->setAccessType('offline');

        if ($step === 1) {
            return $this->handleStep1($client);
        }

        if ($step === 2 && $code !== null) {
            return $this->handleStep2($client, $code);
        }

        return new DataResponse(
            ['status' => 'error', 'data' => []],
            Http::STATUS_BAD_REQUEST,
        );
    }

    /**
     * Create the Google Drive mount automatically after OAuth.
     *
     * @NoAdminRequired
     */
    public function createMount(string $token): DataResponse
    {
        try {
            $userStoragesService = \OC::$server->get(UserStoragesService::class);

            $storage = new StorageConfig();
            $storage->setMountPoint('/Google Drive');
            $storage->setBackendOption('token', $token);
            $storage->setBackendOption('configured', 'true');

            $backendService = \OC::$server->get(\OCA\Files_External\Service\BackendService::class);
            $backend = $backendService->getBackend('files_external_gdrive');
            $authMechanism = $backendService->getAuthMechanism('oauth2::oauth2');

            $storage->setBackend($backend);
            $storage->setAuthMechanism($authMechanism);

            $storage->setMountOptions([
                'encrypt' => true,
                'previews' => true,
                'enable_sharing' => false,
                'filesystem_check_changes' => 1,
                'encoding_compatibility' => false,
                'readonly' => false,
            ]);

            $newStorage = $userStoragesService->addStorage($storage);

            return new DataResponse([
                'status' => 'success',
                'data' => ['id' => $newStorage->getId()],
            ]);
        } catch (\Exception $e) {
            return new DataResponse(
                ['status' => 'error', 'data' => ['message' => $e->getMessage()]],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    private function handleStep1(GoogleClient $client): DataResponse
    {
        try {
            $authUrl = $client->createAuthUrl();
            return new DataResponse([
                'status' => 'success',
                'data' => ['url' => $authUrl],
            ]);
        } catch (\Exception $e) {
            return new DataResponse(
                [
                    'status' => 'error',
                    'data' => [
                        'message' => $this->l10n->t(
                            'Step 1 failed. Exception: %s',
                            [$e->getMessage()],
                        ),
                    ],
                ],
                Http::STATUS_UNPROCESSABLE_ENTITY,
            );
        }
    }

    private function handleStep2(GoogleClient $client, string $code): DataResponse
    {
        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                return new DataResponse(
                    ['status' => 'error', 'data' => $token],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            return new DataResponse([
                'status' => 'success',
                'data' => ['token' => json_encode($token)],
            ]);
        } catch (\Exception $e) {
            return new DataResponse(
                [
                    'status' => 'error',
                    'data' => [
                        'message' => $this->l10n->t(
                            'Step 2 failed. Exception: %s',
                            [$e->getMessage()],
                        ),
                    ],
                ],
                Http::STATUS_UNPROCESSABLE_ENTITY,
            );
        }
    }
}
