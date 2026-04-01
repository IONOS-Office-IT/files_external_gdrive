(function () {
    'use strict';

    var SAVE_URL = OC.generateUrl('/apps/files_external_gdrive/settings/credentials');

    document.getElementById('gdrive-credentials-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var status = document.getElementById('gdrive-save-status');
        status.textContent = 'Saving…';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', SAVE_URL);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('requesttoken', OC.requestToken);
        xhr.onload = function () {
            if (xhr.status === 200) {
                status.textContent = 'Saved';
                status.style.color = 'var(--color-success)';
            } else {
                status.textContent = 'Error saving credentials';
                status.style.color = 'var(--color-error)';
            }
            setTimeout(function () { status.textContent = ''; }, 3000);
        };
        xhr.onerror = function () {
            status.textContent = 'Network error';
            status.style.color = 'var(--color-error)';
        };
        xhr.send(
            'client_id=' + encodeURIComponent(document.getElementById('gdrive-client-id').value) +
            '&client_secret=' + encodeURIComponent(document.getElementById('gdrive-client-secret').value)
        );
    });
})();
