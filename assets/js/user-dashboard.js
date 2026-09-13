(function () {
    'use strict';
    var button = document.querySelector('.temple-dashboard .ui-menu-toggle');
    if (!button) return;
    var desktop = window.matchMedia('(min-width: 1100px)');
    function update() {
        var expanded = desktop.matches
            ? !document.body.classList.contains('dashboard-sidebar-collapsed')
            : document.body.classList.contains('ui-menu-open');
        button.setAttribute('aria-expanded', String(expanded));
        button.setAttribute('aria-label', expanded ? 'ปิดเมนูหลัก' : 'เปิดเมนูหลัก');
    }
    // Desktop collapses the sidebar; mobile keeps the existing drawer and focus handling.
    button.addEventListener('click', function (event) {
        if (!desktop.matches) return;
        event.stopImmediatePropagation();
        document.body.classList.toggle('dashboard-sidebar-collapsed');
        update();
    }, true);
    window.addEventListener('resize', update);
    update();
})();
