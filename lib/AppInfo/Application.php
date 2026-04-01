<?php

declare(strict_types=1);

namespace OCA\Files_External_Gdrive\AppInfo;

use OCA\Files_External\Lib\Config\IAuthMechanismProvider;
use OCA\Files_External\Lib\Config\IBackendProvider;
use OCA\Files_External\Service\BackendService;
use OCA\Files_External_Gdrive\Auth\OAuth2;
use OCA\Files_External_Gdrive\Backend\Google;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap, IBackendProvider, IAuthMechanismProvider
{
    public const APP_ID = 'files_external_gdrive';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';
    }

    public function boot(IBootContext $context): void
    {
        $context->injectFn(function (IAppManager $appManager, BackendService $backendService): void {
            if (!$appManager->isEnabledForUser('files_external')) {
                return;
            }

            $backendService->registerBackendProvider($this);
            $backendService->registerAuthMechanismProvider($this);
        });

        Util::addInitScript(self::APP_ID, 'gdrive');
    }

    public function getBackends(): array
    {
        $container = $this->getContainer();

        return [
            $container->get(Google::class),
        ];
    }

    public function getAuthMechanisms(): array
    {
        $container = $this->getContainer();

        return [
            $container->get(OAuth2::class),
        ];
    }
}
