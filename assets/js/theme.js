(function () {
    'use strict';
    var key = 'profix-theme';
    var system = window.matchMedia('(prefers-color-scheme: dark)');
    var preference = null;
    try { preference = localStorage.getItem(key); } catch (error) { /* Storage may be blocked. */ }
    function apply(value) {
        var dark = value === 'dark' || (value !== 'light' && system.matches);
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    }
    // Runs in the head so a saved dark theme is applied before the page is painted.
    apply(preference);
    system.addEventListener('change', function () { if (preference !== 'dark' && preference !== 'light') apply(null); });
    window.addEventListener('storage', function (event) {
        if (event.key === key || event.key === null) { preference = event.newValue; apply(preference); }
    });
})();
