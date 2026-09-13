(function () {
    'use strict';
    var dataElement = document.getElementById('thaiAddressData');
    if (!dataElement) return;
    var data = JSON.parse(dataElement.textContent);
    var province = document.getElementById('addressProvince');
    var district = document.getElementById('addressDistrict');
    var subdistrict = document.getElementById('addressSubdistrict');
    var postcode = document.getElementById('addressPostcode');

    function fill(select, rows, placeholder, selected) {
        select.replaceChildren(new Option(placeholder, ''));
        rows.forEach(function (row) { select.add(new Option(row.name, row.id)); });
        select.value = rows.some(function (row) { return row.id === selected; }) ? selected : '';
    }
    function selectedProvince() {
        return data.find(function (row) { return row.id === province.value; });
    }
    function selectedDistrict() {
        var parent = selectedProvince();
        return parent && parent.districts.find(function (row) { return row.id === district.value; });
    }
    function updatePostcode() {
        var parent = selectedDistrict();
        var selected = parent && parent.subdistricts.find(function (row) { return row.id === subdistrict.value; });
        postcode.value = selected ? selected.postcode : '';
    }
    function updateSubdistrict(selected) {
        var parent = selectedDistrict();
        fill(subdistrict, parent ? parent.subdistricts : [], parent ? 'เลือกตำบล/แขวง' : 'กรุณาเลือกอำเภอ/เขตก่อน', selected);
        updatePostcode();
    }
    function updateDistrict(selectedDistrictId, selectedSubdistrictId) {
        var parent = selectedProvince();
        if (!district.disabled) {
            fill(district, parent ? parent.districts : [], parent ? 'เลือกอำเภอ/เขต' : 'กรุณาเลือกจังหวัดก่อน', selectedDistrictId);
        }
        updateSubdistrict(selectedSubdistrictId);
    }
    province.addEventListener('change', function () { updateDistrict('', ''); });
    district.addEventListener('change', function () { updateSubdistrict(''); });
    subdistrict.addEventListener('change', updatePostcode);
    updateDistrict(district.value, subdistrict.value);
})();
