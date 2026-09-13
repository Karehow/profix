// Back/forward cache can restore the old DOM even when HTTP caching is disabled.
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
