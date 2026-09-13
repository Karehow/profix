<?php

const REGISTRATION_PROVINCE_ID = '19'; // นครราชสีมา in the local address dataset.
const REGISTRATION_DISTRICT_ID = '3006'; // จักราช in the local address dataset.

function thai_address_data(): array
{
    static $data = null;
    if ($data === null) {
        $data = json_decode(file_get_contents(__DIR__ . '/../assets/data/thai-addresses.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    return $data;
}

function thai_address_selection(array $input): array
{
    $provinceId = is_string($input['province_id'] ?? null) ? $input['province_id'] : '';
    $districtId = is_string($input['district_id'] ?? null) ? $input['district_id'] : '';
    $subdistrictId = is_string($input['subdistrict_id'] ?? null) ? $input['subdistrict_id'] : '';
    $province = $district = $subdistrict = null;
    foreach (thai_address_data() as $candidate) {
        if ($candidate['id'] === $provinceId) { $province = $candidate; break; }
    }
    foreach ($province['districts'] ?? [] as $candidate) {
        if ($candidate['id'] === $districtId) { $district = $candidate; break; }
    }
    foreach ($district['subdistricts'] ?? [] as $candidate) {
        if ($candidate['id'] === $subdistrictId) { $subdistrict = $candidate; break; }
    }
    return [$province, $district, $subdistrict];
}

function registration_villages(): array
{
    static $data = null;
    return $data ??= json_decode(file_get_contents(__DIR__ . '/../assets/data/registration-villages.json'), true, 512, JSON_THROW_ON_ERROR);
}

function thai_address_format(array $input): string
{
    $choice = $input['village_choice'] ?? '';
    if (!is_string($choice)) throw new InvalidArgumentException('กรุณาเลือกหมู่บ้านให้ถูกต้อง');
    if ($choice !== '' && $choice !== 'other') {
        if (!preg_match('/^(\d{6})-([1-9][0-9]*)$/D', $choice, $match) || $match[1] !== ($input['subdistrict_id'] ?? '')) throw new InvalidArgumentException('หมู่บ้านไม่ตรงกับตำบลที่เลือก');
        $name = registration_villages()[$match[1]][(int) $match[2]-1] ?? null;
        if ($name === null) throw new InvalidArgumentException('ไม่พบหมู่บ้านที่เลือก');
        $input['village_name'] = $name;
        $input['village_number'] = $match[2];
    }
    if (array_key_exists('house_number', $input)) {
        $parts = [];
        foreach (['house_number'=>100, 'village_number'=>20, 'village_name'=>200] as $key=>$limit) {
            if (!is_string($input[$key] ?? '') || mb_strlen(trim($input[$key] ?? ''), 'UTF-8') > $limit) throw new InvalidArgumentException('รายละเอียดที่อยู่ไม่ถูกต้องหรือยาวเกินกำหนด');
            $parts[$key] = trim($input[$key] ?? '');
        }
        if ($parts['house_number'] === '') throw new InvalidArgumentException('กรุณากรอกบ้านเลขที่');
        if ($parts['village_number'] !== '' && !preg_match('/^[0-9๐-๙]+$/u', $parts['village_number'])) throw new InvalidArgumentException('กรุณากรอกหมู่เป็นตัวเลข');
        $input['address_detail'] = $parts['house_number']
            . ($parts['village_number'] !== '' ? ' หมู่ ' . $parts['village_number'] : '')
            . ($parts['village_name'] !== '' ? ' หมู่บ้าน' . $parts['village_name'] : '');
    }
    $detail = is_string($input['address_detail'] ?? null) ? trim($input['address_detail']) : '';
    if ($detail === '' || mb_strlen($detail, 'UTF-8') > 1000) {
        throw new InvalidArgumentException('กรุณากรอกบ้านเลขที่และรายละเอียดที่อยู่ ไม่เกิน 1,000 ตัวอักษร');
    }
    [$province, $district, $subdistrict] = thai_address_selection($input);
    if (!$province || !$district || !$subdistrict) {
        throw new InvalidArgumentException('กรุณาเลือกจังหวัด อำเภอ/เขต และตำบล/แขวงให้ครบและสัมพันธ์กัน');
    }
    $bangkok = $province['name'] === 'กรุงเทพมหานคร';
    $districtName = $bangkok
        ? (str_starts_with($district['name'], 'เขต') ? $district['name'] : 'เขต' . $district['name'])
        : 'อำเภอ' . $district['name'];
    return $detail . ' ' . ($bangkok ? 'แขวง' : 'ตำบล') . $subdistrict['name']
        . ' ' . $districtName . ' ' . ($bangkok ? '' : 'จังหวัด') . $province['name']
        . ' ' . $subdistrict['postcode'];
}
