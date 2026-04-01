/**
 * Google Drive OAuth2 Auth Mechanism handler for Nextcloud 33.
 *
 * Client credentials are stored centrally by the admin.
 * Users only click "Grant access" to connect their Google Drive.
 * The mount is created automatically after OAuth consent.
 */
(function () {
    'use strict';

    var APP_ID = 'files_external_gdrive';
    var OAUTH_URL = OC.generateUrl('/apps/' + APP_ID + '/oauth');

    // ── Handle OAuth callback at page level ──────────────────────
    function checkOAuthCallback() {
        var params = {};
        window.location.search.replace(/[?&]+([^=&]+)=([^&]*)/gi, function (m, key, value) {
            params[key] = decodeURIComponent(value);
        });

        if (!params.code) return;

        var redirect = location.protocol + '//' + location.host + location.pathname;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', OAUTH_URL);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('requesttoken', OC.requestToken);
        xhr.onload = function () {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result && result.status === 'success' && result.data && result.data.token) {
                    createStorage(result.data.token);
                } else {
                    var msg = (result.data && result.data.message) || 'Failed to verify authorization code';
                    OC.dialogs.alert(msg, 'Google Drive');
                }
            } catch (e) {
                console.error('Google Drive OAuth error:', e);
                OC.dialogs.alert('Unexpected error during token exchange', 'Google Drive');
            }
            // Clean OAuth params from URL
            window.history.replaceState({}, document.title, redirect);
        };
        xhr.onerror = function () {
            OC.dialogs.alert('Network error during token exchange', 'Google Drive');
        };
        xhr.send(
            'step=2' +
            '&redirect=' + encodeURIComponent(redirect) +
            '&code=' + encodeURIComponent(params.code)
        );
    }

    function createStorage(token) {
        var apiUrl = OC.generateUrl('/apps/' + APP_ID + '/create-mount');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('requesttoken', OC.requestToken);
        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                window.location.reload();
            } else {
                console.error('Failed to create storage:', xhr.status, xhr.responseText);
                OC.dialogs.alert(
                    'Google Drive was authorized but the storage could not be created automatically. Please add it manually.',
                    'Google Drive'
                );
            }
        };
        xhr.onerror = function () {
            OC.dialogs.alert('Network error creating storage', 'Google Drive');
        };
        xhr.send('token=' + encodeURIComponent(token));
    }

    // Run callback check immediately on page load
    checkOAuthCallback();

    // ── Web Component for edit dialog ────────────────────────────
    class GDriveOAuth2Element extends HTMLElement {
        constructor() {
            super();
            this._model = {};
            this._authMechanism = {};
        }

        set modelValue(val) {
            this._model = val || {};
            this._render();
        }

        get modelValue() {
            return this._model;
        }

        set authMechanism(val) {
            this._authMechanism = val || {};
        }

        _emit(newModel) {
            this._model = newModel;
            this.dispatchEvent(new CustomEvent('update:modelValue', {
                detail: newModel,
                bubbles: true,
            }));
        }

        connectedCallback() {
            this._render();
        }

        _render() {
            var isConfigured = this._model.configured === 'true';

            this.innerHTML =
                '<div style="display:flex;flex-direction:column;gap:8px;padding:4px 0">' +
                    (isConfigured
                        ? '<div style="display:flex;align-items:center;gap:8px;color:var(--color-success)">' +
                            '<span style="font-size:20px">&#10003;</span>' +
                            '<span>Connected to Google Drive</span>' +
                            '<button type="button" data-action="revoke" style="margin-left:auto;padding:4px 12px;border:1px solid var(--color-border-dark);border-radius:var(--border-radius);background:var(--color-background-hover);cursor:pointer">Disconnect</button>' +
                          '</div>'
                        : '<button type="button" data-action="grant" style="padding:8px 16px;border:none;border-radius:var(--border-radius);background:var(--color-primary);color:var(--color-primary-text);cursor:pointer;font-weight:bold;align-self:flex-start">' +
                            'Grant access to Google Drive' +
                          '</button>'
                    ) +
                '</div>';

            var self = this;

            var grantBtn = this.querySelector('[data-action="grant"]');
            if (grantBtn) {
                grantBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self._startOAuth();
                });
            }

            var revokeBtn = this.querySelector('[data-action="revoke"]');
            if (revokeBtn) {
                revokeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self._emit(Object.assign({}, self._model, {
                        token: '',
                        configured: 'false',
                    }));
                });
            }
        }

        _startOAuth() {
            var redirect = location.protocol + '//' + location.host + location.pathname;

            var btn = this.querySelector('[data-action="grant"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Redirecting to Google…';
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', OAUTH_URL);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('requesttoken', OC.requestToken);
            xhr.onload = function () {
                try {
                    var result = JSON.parse(xhr.responseText);
                    if (result && result.status === 'success' && result.data && result.data.url) {
                        window.location.href = result.data.url;
                    } else {
                        var msg = (result.data && result.data.message) || 'Failed to get authorization URL. Has the admin configured Google Drive credentials?';
                        OC.dialogs.alert(msg, 'Google Drive');
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = 'Grant access to Google Drive';
                        }
                    }
                } catch (e) {
                    OC.dialogs.alert('Unexpected response from server', 'Google Drive');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Grant access to Google Drive';
                    }
                }
            };
            xhr.onerror = function () {
                OC.dialogs.alert('Network error contacting OAuth endpoint', 'Google Drive');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Grant access to Google Drive';
                }
            };
            xhr.send(
                'step=1' +
                '&redirect=' + encodeURIComponent(redirect)
            );
        }
    }

    // Register the custom element
    customElements.define('files-external-gdrive-oauth2', GDriveOAuth2Element);

    // ── Register with NC 33 external storage handler system ──────
    function registerHandler() {
        if (window.OCA && window.OCA.FilesExternal && window.OCA.FilesExternal.AuthMechanism) {
            window.OCA.FilesExternal.AuthMechanism.registerHandler({
                id: 'gdrive-oauth2',
                tagName: 'files-external-gdrive-oauth2',
                enabled: function (authMechanism) {
                    return authMechanism.identifier === 'oauth2::oauth2';
                },
            });
            return true;
        }
        return false;
    }

    if (!registerHandler()) {
        var attempts = 0;
        var interval = setInterval(function () {
            if (registerHandler() || ++attempts > 50) {
                clearInterval(interval);
            }
        }, 100);
    }
})();
