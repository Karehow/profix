document.querySelectorAll('[data-branding-preview]').forEach(input => {
    let url;
    const preview = document.getElementById(input.dataset.brandingPreview);
    const savedSrc = preview.getAttribute('src');
    const reset = input.form.elements['reset_' + input.name];
    reset.addEventListener('change', () => {
        preview.src = reset.checked ? preview.dataset.defaultSrc : (url || savedSrc);
    });
    input.addEventListener('change', () => {
        if (url) URL.revokeObjectURL(url);
        url = null;
        preview.src = reset.checked ? preview.dataset.defaultSrc : savedSrc;
        input.setCustomValidity('');
        const file = input.files[0];
        if (!file) { url = null; preview.src = reset.checked ? preview.dataset.defaultSrc : savedSrc; return; }
        if (file.size > 5 * 1024 * 1024) { input.setCustomValidity('กรุณาเลือกรูปไม่เกิน 5 MB'); input.reportValidity(); return; }
        input.setCustomValidity('');
        url = URL.createObjectURL(file);
        document.getElementById(input.dataset.brandingPreview).src = url;
        input.form.elements['reset_' + input.name].checked = false;
    });
});
