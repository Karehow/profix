(function () {
    'use strict';
    var dataNode = document.getElementById('registrationVillageData');
    if (!dataNode) return;
    var data = JSON.parse(dataNode.textContent), select = document.getElementById('addressVillageSelect');
    var subdistrict = document.getElementById('addressSubdistrict'), moo = document.getElementById('addressMoo');
    var village = document.getElementById('addressVillage'), manual = document.getElementById('villageManual');
    function apply(clear) {
        var row = select.options[select.selectedIndex];
        var known = row && row.dataset.moo;
        manual.hidden = select.value !== 'other';
        moo.readOnly = Boolean(known);
        if (known) { moo.value = row.dataset.moo; village.value = row.dataset.name; }
        else if (clear) { moo.value = ''; village.value = ''; }
        if (clear) village.dispatchEvent(new Event('input', {bubbles:true}));
    }
    function fill(selected, clear) {
        select.replaceChildren(new Option(subdistrict.value ? 'เลือกหมู่บ้าน' : 'กรุณาเลือกตำบลก่อน', ''));
        (data[subdistrict.value] || []).forEach(function (name, index) {
            var option = new Option(name + ' — หมู่ ' + (index+1), subdistrict.value + '-' + (index+1));
            option.dataset.moo = String(index+1); option.dataset.name = name; select.add(option);
        });
        select.add(new Option('ไม่พบหมู่บ้านในรายการ / กรอกเอง', 'other'));
        select.value = Array.from(select.options).some(function (o) {return o.value === selected;}) ? selected : '';
        apply(clear);
    }
    subdistrict.addEventListener('change', function () { fill('', true); });
    document.getElementById('addressDistrict').addEventListener('change', function () { fill('', true); });
    select.addEventListener('change', function () { apply(true); });
    fill(select.dataset.selected || (village.value ? 'other' : ''), false);
})();
