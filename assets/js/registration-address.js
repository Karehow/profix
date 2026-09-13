(function () {
    'use strict';
    // Adapted from project/patient.php: village-first search, broader fallbacks,
    // and a draggable marker. Uses this application's existing geocoder.
    var root = document.getElementById('authMapDetails');
    if (!root) return;
    var latitude = document.getElementById('latitude'), longitude = document.getElementById('longitude');
    var status = document.getElementById('mapStatus'), button = document.getElementById('findAddressLocation');
    var house = document.getElementById('addressHouse'), moo = document.getElementById('addressMoo'), village = document.getElementById('addressVillage');
    var province = document.getElementById('addressProvince'), district = document.getElementById('addressDistrict'), subdistrict = document.getElementById('addressSubdistrict');
    var map, marker, revision = 0, controller, cache = new Map();
    var confirmLocation = document.getElementById('confirmMapLocation'), suggestedPoint = null;
    var locationData = document.getElementById('registrationVillageLocations');
    var villageLocations = locationData ? JSON.parse(locationData.textContent) : {};
    function knownVillage() {
        var row = villageLocations[subdistrict.value + '-' + normalize(moo.value)];
        if (!row || row.province_id !== province.value || row.district_id !== district.value || row.subdistrict_id !== subdistrict.value || normalize(row.village_name) !== normalize(village.value)) return null;
        return Number.isFinite(row.lat) && Number.isFinite(row.lng) && row.lat >= 5 && row.lat <= 21 && row.lng >= 97 && row.lng <= 106 ? row : null;
    }
    function showKnownVillage(row, searchFailed) {
        var point = {lat:row.lat, lng:row.lng};
        place(point); map.setView(point, 16); suggestedPoint = point;
        if (confirmLocation) confirmLocation.hidden = false;
        status.textContent = (searchFailed ? 'ค้นหาบ้านเลขที่ไม่สำเร็จ แต่' : '') + 'พบจุดอ้างอิงบ้าน' + row.village_name + ' หมู่ ' + row.village_number + ' ตำบล' + name(subdistrict) + ' แล้ว หมุดนี้เป็นจุดอ้างอิงในหมู่บ้าน ไม่ใช่พิกัดบ้านเลขที่ กรุณาลากหมุดไปที่บ้านจริงหรือยืนยันตำแหน่งนี้';
    }
    function stop() { revision++; if (controller) controller.abort(); button.disabled = false; button.textContent = 'ค้นหาตำแหน่งจากที่อยู่'; }
    function save(point, text) { latitude.value = point.lat.toFixed(8); longitude.value = point.lng.toFixed(8); status.textContent = text; suggestedPoint = null; if (confirmLocation) confirmLocation.hidden = true; }
    function place(point) {
        if (marker) marker.setLatLng(point);
        else {
            marker = L.marker(point, {draggable:true}).addTo(map);
            marker.on('dragstart', stop);
            marker.on('dragend', function () { stop(); save(marker.getLatLng(), 'เลือกตำแหน่งจากแผนที่แล้ว'); });
        }
    }
    function referenceMarker(message) {
        if (!map) return;
        var center = map.getCenter ? map.getCenter() : {lat:Number(root.dataset.lat), lng:Number(root.dataset.lng)};
        place(center);
        suggestedPoint = null;
        if (confirmLocation) confirmLocation.hidden = true;
        status.textContent = message + ' หมุดที่แสดงเป็นจุดเริ่มต้นสำหรับลากเลือกบ้านจริง ยังไม่บันทึกพิกัด';
    }
    [house, moo, village].forEach(function (input) { input.addEventListener('input', invalidate); });
    [province, district, subdistrict].forEach(function (select) { select.addEventListener('change', invalidate); });
    function initMap() {
        if (map) return true;
        if (!window.L) { status.textContent = 'แผนที่ยังโหลดไม่สำเร็จ กรุณาโหลดหน้าใหม่แล้วกดค้นหาอีกครั้ง'; return false; }
        try {
            var saved = latitude.value !== '' && longitude.value !== '' && Number.isFinite(Number(latitude.value)) && Number.isFinite(Number(longitude.value));
            var center = {lat:Number(saved ? latitude.value : root.dataset.lat), lng:Number(saved ? longitude.value : root.dataset.lng)};
            map = L.map('map').setView(center, saved ? 16 : 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> | <a href="https://photon.komoot.io">Photon</a>'}).addTo(map);
            if (saved) { place(center); status.textContent = 'ตำแหน่งที่เลือกไว้ ลากหมุดเพื่อแก้ไขได้'; }
            else referenceMarker('เลือกพื้นที่แล้วกดค้นหาตำแหน่งได้');
            map.on('click', function (event) { stop(); place(event.latlng); save(event.latlng, 'เลือกตำแหน่งจากแผนที่แล้ว'); });
            return true;
        } catch (error) {
            status.textContent = 'เปิดแผนที่ไม่สำเร็จ กรุณาโหลดหน้าใหม่ ยังสมัครด้วยที่อยู่ที่กรอกไว้ได้';
            return false;
        }
    }
    function invalidate() {
        stop(); latitude.value = longitude.value = '';
        suggestedPoint = null; if (confirmLocation) confirmLocation.hidden = true;
        referenceMarker('ที่อยู่เปลี่ยนแล้ว กรุณาค้นหาหรือเลือกตำแหน่งใหม่');
    }
    function name(select) { return select.value ? select.options[select.selectedIndex].text : ''; }
    function normalize(text) { return String(text || '').trim().replace(/[๐-๙]/g, function (d) { return String('๐๑๒๓๔๕๖๗๘๙'.indexOf(d)); }).replace(/^(องค์การบริหารส่วนตำบล|เทศบาลตำบล|หมู่บ้าน|บ้าน|ตำบล|อำเภอ|จังหวัด|ต\.|อ\.|จ\.)/, '').replace(/\s/g, '').toLowerCase(); }
    // API fields and filters: https://github.com/komoot/photon/blob/master/docs/api-v1.md
    async function lookup(query, signal, expected, near, level) {
        var cacheKey = query + '|' + (near ? near.lat + ',' + near.lng : '');
        var features = cache.get(cacheKey);
        if (!features) {
            var url = new URL(root.dataset.geocoder);
            url.search = new URLSearchParams({q:query, limit:'10', countrycode:'TH'});
            if (near) url.searchParams.set('bbox', [near.lng-.02, near.lat-.02, near.lng+.02, near.lat+.02].join(','));
            var response = await fetch(url.toString(), {signal:signal, credentials:'omit'});
            if (!response.ok) throw new Error('Search unavailable');
            var result = await response.json();
            if (!Array.isArray(result.features)) throw new Error('Invalid results');
            features = result.features; cache.set(cacheKey, features);
        }
        var matches = features.filter(function (feature) {
            var p = feature.properties || {}, c = feature.geometry && feature.geometry.coordinates;
            if (!feature.geometry || feature.geometry.type !== 'Point' || !Array.isArray(c) || !Number.isFinite(c[0]) || !Number.isFinite(c[1])) return false;
            var provinceMatch = /นครราชสีมา|nakhon ratchasima/i.test(p.state || '') || (level === 'province' && normalize(p.name) === normalize(name(province)));
            if (c[0]<97 || c[0]>106 || c[1]<5 || c[1]>21 || !provinceMatch) return false;
            if (near && (Math.abs(c[0]-near.lng) > .02 || Math.abs(c[1]-near.lat) > .02)) return false;
            var adminNames = [p.county, p.city, p.district].map(normalize);
            var districtMatch = adminNames.includes(normalize(name(district)));
            // Sparse address metadata may be used for a labelled area suggestion only.
            // A conflicting Thai county is still rejected, and houses remain strict.
            if (level !== 'province' && !districtMatch && !(level === 'district' && normalize(p.name) === normalize(name(district)))) {
                if (level === 'house' || /[ก-๙]/.test(p.county || '')) return false;
            }
            if (level === 'house' && subdistrict.value && !adminNames.includes(normalize(name(subdistrict)))) return false;
            if (level === 'village' && subdistrict.value && /[ก-๙]/.test(p.district || '') && normalize(p.district) !== normalize(name(subdistrict)) && !adminNames.includes(normalize(name(subdistrict)))) return false;
            if (level === 'house') {
                // A postcode, OSM ID or place name equal to the house number is not a house match.
                if (!p.housenumber || normalize(p.housenumber) !== normalize(expected)) return false;
                if (village.value.trim() && ![p.locality, p.district, p.city].map(normalize).includes(normalize(village.value))) return false;
                return true;
            }
            return normalize(p.name) === normalize(expected);
        });
        // Do not silently choose between distinct places sharing the same name/number.
        var match = matches[0];
        if (match && matches.some(function (other) { return Math.abs(other.geometry.coordinates[0]-match.geometry.coordinates[0]) > .002 || Math.abs(other.geometry.coordinates[1]-match.geometry.coordinates[1]) > .002; })) return null;
        return match ? {lat:match.geometry.coordinates[1], lng:match.geometry.coordinates[0]} : null;
    }
    button.addEventListener('click', async function () {
        stop();
        if (!initMap()) return;
        if (!district.value) { status.textContent = 'กรุณาเลือกอำเภอก่อนค้นหาตำแหน่ง'; district.focus(); return; }
        latitude.value = longitude.value = ''; suggestedPoint = null;
        if (confirmLocation) confirmLocation.hidden = true;
        var current = revision, request = new AbortController(); controller = request;
        button.disabled = true; button.textContent = 'กำลังค้นหา…'; status.textContent = 'กำลังค้นหาตำแหน่งจากที่อยู่…';
        var timeout = setTimeout(function () { request.abort(); }, 20000);
        var area = [name(subdistrict), name(district), name(province)].join(' ');
        var localVillage = knownVillage();
        try {
            if (localVillage) {
                showKnownVillage(localVillage, false);
                if (!house.value.trim()) return;
            }
            var villagePoint = localVillage ? {lat:localVillage.lat, lng:localVillage.lng} : (village.value.trim() ? await lookup(village.value.trim() + ' ' + area, request.signal, village.value.trim(), null, 'village') : null);
            if (current !== revision) return;
            var point = house.value.trim() ? await lookup([house.value.trim(), moo.value.trim() ? 'หมู่ '+moo.value.trim() : '', village.value.trim(), area].join(' '), request.signal, house.value.trim(), villagePoint, 'house') : null;
            if (current !== revision) return;
            var level = 'บริเวณบ้านเลขที่', zoom = 16;
            var houseMatched = Boolean(point);
            if (!point && villagePoint) { point = villagePoint; level = 'หมู่บ้าน'; zoom = 14; }
            var fallbacks = (subdistrict.value ? [[area,'ตำบล',14,name(subdistrict),'subdistrict']] : []).concat([[name(district)+' '+name(province),'อำเภอ',12,name(district),'district'], [name(province),'จังหวัด',10,name(province),'province']]);
            for (var i=0; !point && i<fallbacks.length; i++) {
                point = await lookup(fallbacks[i][0], request.signal, fallbacks[i][3], null, fallbacks[i][4]);
                if (current !== revision) return;
                level = fallbacks[i][1]; zoom = fallbacks[i][2];
            }
            if (!point) { referenceMarker('ไม่พบตำแหน่งจากที่อยู่ที่กรอก'); return; }
            place(point); map.setView(point, zoom);
            if (houseMatched) save(point, 'พบเลขที่บ้านตรงกับที่กรอกในพื้นที่ที่เลือก กรุณาตรวจสอบหมุดก่อนสมัคร');
            else if (localVillage) showKnownVillage(localVillage, false);
            else {
                suggestedPoint = point;
                if (confirmLocation) confirmLocation.hidden = false;
                status.textContent = 'ยังไม่พบพิกัดบ้านเลขที่จริง แสดงบริเวณ'+level+'ให้ตรวจสอบเท่านั้น ยังไม่บันทึกพิกัด กรุณาลากหมุดไปที่บ้านจริง หรือยืนยันตำแหน่งนี้';
            }
        } catch (error) {
            if (current === revision) {
                if (localVillage) showKnownVillage(localVillage, true);
                else referenceMarker('ค้นหาไม่สำเร็จ ลองใหม่หรือเลือกตำแหน่งบนแผนที่ได้');
            }
        } finally {
            clearTimeout(timeout);
            if (current === revision) { button.disabled = false; button.textContent = 'ค้นหาตำแหน่งจากที่อยู่'; }
            if (controller === request) controller = null;
        }
    });
    if (confirmLocation) confirmLocation.addEventListener('click', function () {
        if (!suggestedPoint) return;
        stop(); save(suggestedPoint, 'ยืนยันตำแหน่งที่เลือกแล้ว ลากหมุดเพื่อปรับแก้ได้');
    });
    initMap();
})();
