<?php
function branding_settings(): array
{
    $data = json_decode(@file_get_contents(__DIR__ . '/branding.local.json') ?: '{}', true);
    $settings = [];
    foreach (['logo', 'banner'] as $key) {
        if (isset($data[$key]) && is_string($data[$key]) && preg_match('~^uploads/branding/[a-f0-9]{32}\.png$~D', $data[$key])) $settings[$key] = $data[$key];
    }
    return $settings;
}

function branding_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลดไม่สำเร็จ กรุณาเลือกรูปใหม่ (ไม่เกิน 5 MB)');
    if (!is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 5 * 1024 * 1024) throw new RuntimeException('รูปต้องมีขนาดไม่เกิน 5 MB');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 16000000) throw new RuntimeException('รองรับ JPG, PNG และ WEBP ขนาดไม่เกิน 16 ล้านพิกเซล');
    $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$image) throw new RuntimeException('ไม่สามารถอ่านรูปภาพนี้ได้');
    imagesavealpha($image, true);
    $directory = __DIR__ . '/../uploads/branding';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('ไม่สามารถสร้างที่เก็บรูปได้');
    $path = 'uploads/branding/' . bin2hex(random_bytes(16)) . '.png';
    try {
        if (!imagepng($image, __DIR__ . '/../' . $path)) throw new RuntimeException('บันทึกรูปภาพไม่สำเร็จ');
    } finally { imagedestroy($image); }
    return $path;
}

function branding_save(array $changes): void
{
    $lock = fopen(__DIR__ . '/branding.local.lock', 'c');
    if (!$lock) throw new RuntimeException('ไม่สามารถบันทึกการตั้งค่าได้');
    $temp = null;
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('ไม่สามารถล็อกการตั้งค่าได้');
        $settings = branding_settings();
        foreach ($changes as $key => $value) {
            if ($value === null) unset($settings[$key]); else $settings[$key] = $value;
        }
        $temp = tempnam(__DIR__, 'branding-');
        if (!$temp || file_put_contents($temp, json_encode($settings, JSON_THROW_ON_ERROR)) === false || !rename($temp, __DIR__ . '/branding.local.json')) throw new RuntimeException('บันทึกการตั้งค่าไม่สำเร็จ');
    } finally {
        if ($temp && is_file($temp)) unlink($temp);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
