(function () {
    'use strict';
    var inbox = document.getElementById('notificationInbox');
    var trigger = document.querySelector('.dashboard-notice');
    if (!inbox || !trigger || !window.bootstrap || !window.bootstrap.Modal) return;

    // Reuse the existing forms, CSRF tokens and pagination inside the dialog.
    // Without JavaScript the inbox remains available on the page.
    var popup = document.createElement('div');
    popup.id = 'notificationPopup';
    popup.className = 'modal fade notification-popup';
    popup.tabIndex = -1;
    popup.setAttribute('aria-labelledby', 'notificationInboxTitle');
    popup.setAttribute('aria-hidden', 'true');
    popup.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">'
        + '<div class="modal-content"><div class="notification-popup-toolbar">'
        + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดการแจ้งเตือน"></button>'
        + '</div><div class="modal-body"></div></div></div>';
    popup.querySelector('.modal-body').appendChild(inbox);
    document.body.appendChild(popup);
    var modal = new window.bootstrap.Modal(popup);
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-controls', popup.id);
    trigger.addEventListener('click', function (event) {
        event.preventDefault();
        modal.show();
    });
    function openFromLink() {
        if (window.location.hash === '#notificationInbox') modal.show();
    }
    popup.addEventListener('hidden.bs.modal', function () {
        if (window.location.hash === '#notificationInbox') {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
        trigger.focus();
    });
    window.addEventListener('hashchange', openFromLink);
    // Read actions and pagination redirect back to the open inbox.
    openFromLink();
})();
