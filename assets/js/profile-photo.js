(function () {
    'use strict';
    var input = document.getElementById('profilePhoto');
    var preview = document.getElementById('profilePhotoPreview');
    var status = document.getElementById('profilePhotoStatus');
    var savedUrl = preview.src;
    var objectUrl = null;
    input.addEventListener('change', function () {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        preview.src = savedUrl;
        status.textContent = '';
        status.classList.remove('text-danger');
        var file = input.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
            status.textContent = 'กรุณาเลือกรูป JPG, PNG หรือ WebP ขนาดไม่เกิน 5 MB';
            status.classList.add('text-danger');
            input.value = '';
            return;
        }
        objectUrl = URL.createObjectURL(file);
        preview.src = objectUrl;
        status.textContent = 'ตัวอย่างรูปใหม่ — กดบันทึกข้อมูลเพื่อใช้รูปนี้';
    });
})();
