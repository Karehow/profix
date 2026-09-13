(function () {
    'use strict';

    var button = document.querySelector('.borrow-back-to-top');
    if (!button) return;

    function updateVisibility() {
        button.hidden = window.scrollY < 300;
    }

    button.addEventListener('click', function () {
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, left: 0, behavior: reduceMotion ? 'instant' : 'smooth' });
    });

    window.addEventListener('scroll', updateVisibility, { passive: true });
    window.addEventListener('pageshow', updateVisibility);
    updateVisibility();
})();
