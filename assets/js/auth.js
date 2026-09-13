(function () {
    'use strict';
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.dataset.passwordToggle);
            var showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            button.textContent = showing ? 'ซ่อน' : 'แสดง';
            button.setAttribute('aria-pressed', String(showing));
            button.setAttribute('aria-label', (showing ? 'ซ่อน ' : 'แสดง ') + (input.dataset.secretLabel || 'รหัสผ่าน'));
        });
    });
    document.querySelectorAll('[data-confirm-for]').forEach(function (input) {
        var original = document.getElementById(input.dataset.confirmFor);
        function validate() { input.setCustomValidity(input.value && input.value !== original.value ? 'กรุณากรอก รหัสผ่าน ทั้งสองช่องให้ตรงกัน' : ''); }
        input.addEventListener('input', validate);
        original.addEventListener('input', validate);
    });
    var error = document.querySelector('[data-auth-error]');
    if (error) error.focus();
})();
