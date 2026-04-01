# External Storage: Google Drive for Nextcloud

Mount Google Drive as an external storage backend in Nextcloud 28–33.

This is a modernized fork of [NastuzziSamy/files_external_gdrive](https://github.com/NastuzziSamy/files_external_gdrive), rewritten for compatibility with current Nextcloud releases.

## What changed from the original

- Extends `\OC\Files\Storage\Common` directly (the Flysystem base was removed in NC 23)
- Uses Google Drive API v3 via `google/apiclient` v2.16+
- Modern app bootstrap via `IBootstrap` interface
- PSR `LoggerInterface` instead of deprecated `\OC::$server->getLogger()`
- PHP 8.1+ with strict types
- Automatic token refresh

## Requirements

- Nextcloud 28 – 33
- PHP 8.1+
- `files_external` app enabled
- A Google Cloud project with the Drive API enabled and an OAuth 2.0 Client ID

## Installation

### Manual

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/YOUR_FORK/files_external_gdrive.git
cd files_external_gdrive
make composer
```

Then enable in Nextcloud:

```bash
occ app:enable files_external_gdrive
```

### From release tarball

```bash
cd /path/to/nextcloud/apps
tar xzf files_external_gdrive.tar.gz
occ app:enable files_external_gdrive
```

## Configuration

1. Go to **Settings → Administration → External storage**
2. Add a new **Google Drive** mount
3. Select **OAuth2** as the authentication mechanism
4. Enter your Google Cloud **Client ID** and **Client Secret**
5. Click **Grant access** and authorize with your Google account

### Creating Google Cloud credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or select an existing one)
3. Enable the **Google Drive API**
4. Go to **Credentials → Create Credentials → OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Add your Nextcloud URL as an authorized redirect URI:
   `https://your-nextcloud.example.com/settings/admin/externalstorages`
7. Copy the Client ID and Client Secret

## License

AGPL-3.0 — see [LICENSE](LICENSE)
