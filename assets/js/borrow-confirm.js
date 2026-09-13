
(function () {
    var pickup = document.getElementById('pickupDate');
    var returned = document.getElementById('returnDate');
    function updateReturnRange() {
        if (!pickup.value) {
            returned.min = pickup.min;
            returned.removeAttribute('max');
            return;
        }
        returned.min = pickup.value;
        var maximum = new Date(pickup.value + 'T00:00:00Z');
        maximum.setUTCDate(maximum.getUTCDate() + 31);
        returned.max = maximum.toISOString().slice(0, 10);
        if (returned.value && (returned.value < returned.min || returned.value > returned.max)) returned.value = '';
    }
    document.querySelectorAll('[data-return-days]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!pickup.value) { pickup.focus(); return; }
            var date = new Date(pickup.value + 'T00:00:00Z');
            if (!Number.isFinite(date.getTime())) return;
            date.setUTCDate(date.getUTCDate() + Number(button.dataset.returnDays));
            returned.value = date.toISOString().slice(0, 10);
            updateReturnRange();
        });
    });
    document.querySelectorAll('.confirm-remove').forEach(function (form) {
        form.addEventListener('submit', function () {
            [['pickup_date', pickup.value], ['expected_return_date', returned.value]].forEach(function (field) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = field[0]; input.value = field[1];
                form.appendChild(input);
            });
        });
    });
    var sending = false;
    document.getElementById('borrowCheckout').addEventListener('submit', function (event) {
        if (sending) { event.preventDefault(); return; }
        sending = true;
        this.querySelector('[name="submit_request"]').textContent = 'กำลังส่งคำขอ…';
    });
    window.addEventListener('pageshow', function () {
        sending = false;
        document.querySelector('[name="submit_request"]').textContent = 'ส่งคำขอยืม →';
    });
    pickup.addEventListener('change', updateReturnRange);
    updateReturnRange();
})();
