<?php

declare(strict_types=1);

namespace OCA\Files_External_Gdrive\Backend;

use OCA\Files_External\Lib\Auth\AuthMechanism;
use OCA\Files_External\Lib\Backend\Backend;
use OCP\IL10N;

class Google extends Backend
{
    public function __construct(IL10N $l)
    {
        $this
            ->setIdentifier('files_external_gdrive')
            ->addIdentifierAlias('\OC\Files\External_Storage\GoogleDrive')
            ->setStorageClass(\OCA\Files_External_Gdrive\Storage\GoogleDrive::class)
            ->setText($l->t('Google Drive'))
            ->addParameters([])
            ->addAuthScheme(AuthMechanism::SCHEME_OAUTH2);
    }
}
