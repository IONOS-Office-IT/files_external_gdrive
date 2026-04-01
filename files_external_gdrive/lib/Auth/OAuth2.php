<?php

declare(strict_types=1);

namespace OCA\Files_External_Gdrive\Auth;

use OCA\Files_External\Lib\Auth\AuthMechanism;
use OCA\Files_External\Lib\DefinitionParameter;
use OCA\Files_External\Lib\StorageConfig;
use OCA\Files_External_Gdrive\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;

/**
 * OAuth2 authentication mechanism for Google Drive.
 *
 * Client credentials are stored centrally by the admin.
 * Users only grant consent; the token is stored per-mount.
 */
class OAuth2 extends AuthMechanism
{
    public function __construct(
        IL10N $l,
        private IConfig $config,
    ) {
        $this
            ->setIdentifier('oauth2::oauth2')
            ->setScheme(self::SCHEME_OAUTH2)
            ->setText($l->t('OAuth2'))
            ->addParameters([
                (new DefinitionParameter('gdrive_status', $l->t('Google Drive connection')))
                    ->setFlag(DefinitionParameter::FLAG_OPTIONAL),
                (new DefinitionParameter('token', $l->t('Token')))
                    ->setType(DefinitionParameter::VALUE_PASSWORD)
                    ->setFlag(DefinitionParameter::FLAG_OPTIONAL | DefinitionParameter::FLAG_HIDDEN),
                (new DefinitionParameter('configured', $l->t('Configured')))
                    ->setFlag(DefinitionParameter::FLAG_OPTIONAL | DefinitionParameter::FLAG_HIDDEN),
            ]);
    }

    /**
     * Inject the centrally stored client credentials into the storage
     * config before the storage backend is constructed.
     */
    public function manipulateStorageConfig(StorageConfig &$storage, ?\OCP\IUser $user = null): void
    {
        $clientId = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
        $clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');

        $storage->setBackendOption('client_id', $clientId);
        $storage->setBackendOption('client_secret', $clientSecret);
        $storage->setBackendOption('mount_id', $storage->getId());

        if ($user !== null) {
            $storage->setBackendOption('user', $user->getUID());
        }
    }
}
