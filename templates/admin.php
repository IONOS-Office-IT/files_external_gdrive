<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

script('files_external_gdrive', 'admin');
?>

<div id="gdrive-admin-settings" class="section">
    <h2>Google Drive</h2>
    <p class="settings-hint">
        <?php p($l->t('Configure the Google OAuth2 credentials. Users will only need to grant consent to access their own Google Drive.')); ?>
    </p>

    <form id="gdrive-credentials-form">
        <div style="display:flex;flex-direction:column;gap:12px;max-width:500px">
            <label>
                <span><?php p($l->t('Client ID')); ?></span>
                <input type="text"
                       id="gdrive-client-id"
                       name="client_id"
                       value="<?php p($_['client_id']); ?>"
                       placeholder="<?php p($l->t('Google OAuth2 Client ID')); ?>"
                       style="width:100%" />
            </label>
            <label>
                <span><?php p($l->t('Client Secret')); ?></span>
                <input type="password"
                       id="gdrive-client-secret"
                       name="client_secret"
                       value="<?php p($_['client_secret']); ?>"
                       placeholder="<?php p($l->t('Google OAuth2 Client Secret')); ?>"
                       style="width:100%" />
            </label>
            <div>
                <button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
                <span id="gdrive-save-status" style="margin-left:8px"></span>
            </div>
        </div>
    </form>
</div>
