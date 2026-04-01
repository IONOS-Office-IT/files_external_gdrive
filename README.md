# External Storage: Google Drive for Nextcloud

Mount Google Drive as an external storage backend in Nextcloud 28-33.

This is a modernized fork of [NastuzziSamy/files_external_gdrive](https://github.com/NastuzziSamy/files_external_gdrive), rewritten for compatibility with current Nextcloud releases.

## What changed from the original

- Extends `\OC\Files\Storage\Common` directly (the Flysystem base was removed in NC 23)
- Uses Google Drive API v3 via `google/apiclient` v2.16+
- Modern app bootstrap via `IBootstrap` interface
- PSR `LoggerInterface` instead of deprecated `\OC::$server->getLogger()`
- PHP 8.1+ with strict types
- Automatic token refresh with persistence back to mount config
- Duplicate filename handling (Google Drive allows duplicates, Nextcloud does not)
- Stable storage ID that survives token refreshes

## Requirements

- Nextcloud 28 - 33
- PHP 8.1+
- `files_external` app enabled
- A Google Cloud project with the Drive API enabled and an OAuth 2.0 Client ID

## Installation

### Manual

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/IONOS-Office-IT/files_external_gdrive.git
cd files_external_gdrive
composer install --no-dev
```

Then enable in Nextcloud:

```bash
php occ app:enable files_external_gdrive
```

### Docker (custom Nextcloud image)

```dockerfile
FROM nextcloud:33-apache
COPY files_external_gdrive /usr/src/nextcloud/custom_apps/files_external_gdrive
COPY files_external_gdrive /var/www/html/custom_apps/files_external_gdrive
```

## Admin Setup

### 1. Create Google Cloud credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or select an existing one)
3. Enable the **Google Drive API**
4. Go to **APIs & Services > Credentials > Create Credentials > OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Add an authorized redirect URI:
   ```
   https://your-nextcloud.example.com/apps/files_external_gdrive/oauth
   ```
7. Copy the **Client ID** and **Client Secret**

### 2. Configure the app

1. Log in as admin
2. Go to **Settings > Administration > External storage**
3. Scroll down to the **Google Drive** section
4. Enter your **Client ID** and **Client Secret**
5. Click **Save**

## User Guide: Connecting Google Drive

Once the admin has configured the OAuth credentials, each user can connect their own Google Drive:

### Step 1: Add external storage

1. Go to **Settings > Personal > External storage**
2. Click **+ Add external storage**
3. Select **Google Drive** as the storage type
4. Set a folder name (e.g. `/Google Drive`)
5. Select **OAuth2** as the authentication method

### Step 2: Grant access

1. Click the **Grant access** button
2. A Google sign-in window will open
3. Select your Google account
4. Review the permissions and click **Allow**
5. The window closes and you return to Nextcloud

### Step 3: Access your files

1. Open the **Files** app
2. Your Google Drive appears as a folder in the sidebar
3. All your Google Drive files are accessible directly from Nextcloud
4. Google Docs/Sheets/Slides are automatically exported as ODT/ODS/ODP

### Troubleshooting

| Issue | Solution |
|---|---|
| "Grant access" button missing | Ask your admin to configure the Google OAuth credentials |
| Red folder icon | Hard-refresh the page (Ctrl+Shift+R) |
| Empty folder | Wait a moment for the initial scan, then refresh |
| Files not updating | Go to Settings > External storage, click the pencil icon, set "Check for changes" to "Every time the filesystem is accessed" |

## License

AGPL-3.0 - see [LICENSE](LICENSE)
