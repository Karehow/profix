(function () {
    'use strict';
    var toggle = document.querySelector('.ui-menu-toggle');
    var sidebar = document.getElementById('siteSidebar');
    var backdrop = document.querySelector('.ui-menu-backdrop');
    function setMenu(open) {
        document.body.classList.toggle('ui-menu-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'ปิดเมนูหลัก' : 'เปิดเมนูหลัก');
        backdrop.hidden = !open;
        if (open) sidebar.querySelector('a').focus();
    }
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () { setMenu(toggle.getAttribute('aria-expanded') !== 'true'); });
        backdrop.addEventListener('click', function () { setMenu(false); toggle.focus(); });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && document.body.classList.contains('ui-menu-open')) { setMenu(false); toggle.focus(); }
            if (event.key === 'Tab' && document.body.classList.contains('ui-menu-open')) {
                var controls = sidebar.querySelectorAll('a, button');
                var first = controls[0], last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        window.addEventListener('resize', function () { if (innerWidth >= 1100 && document.body.classList.contains('ui-menu-open')) setMenu(false); });
    }
    // Keep dialogs above cards and horizontal table scrollers.
    document.querySelectorAll('.modal').forEach(function (modal) { document.body.appendChild(modal); });
    document.querySelectorAll('.table-responsive').forEach(function (region) {
        region.tabIndex = 0;
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', 'ตารางข้อมูล เลื่อนซ้ายขวาเพื่อดูเพิ่มเติม');
        var hint = document.createElement('div');
        hint.className = 'ui-table-hint';
        hint.textContent = 'เลื่อนตารางซ้าย–ขวาเพื่อดูข้อมูลทั้งหมด ↔';
        region.parentNode.insertBefore(hint, region);
        var update = function () { hint.hidden = region.scrollWidth <= region.clientWidth + 2; };
        update();
        if (window.ResizeObserver) new ResizeObserver(update).observe(region);
        else window.addEventListener('resize', update);
    });
    document.querySelectorAll('table thead th').forEach(function (heading) { if (!heading.hasAttribute('scope')) heading.setAttribute('scope','col'); });
})();
