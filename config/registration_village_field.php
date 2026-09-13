<div class="col-md-4"><label for="addressVillageSelect" class="form-label">หมู่บ้าน</label>
<select id="addressVillageSelect" name="village_choice" class="form-select" data-selected="<?= $field('village_choice') ?>" aria-describedby="villageHelp">
<option value="">เลือกตำบลก่อน แล้วเลือกหมู่บ้าน</option>
<?php foreach (registration_villages()[$addressSubdistrict['id'] ?? ''] ?? [] as $index=>$name): $value=($addressSubdistrict['id'] ?? '').'-'.($index+1); ?>
<option value="<?= app_escape($value) ?>" <?= $field('village_choice') === $value ? 'selected' : '' ?>><?= app_escape($name) ?> — หมู่ <?= $index+1 ?></option>
<?php endforeach; ?>
<option value="other" <?= $field('village_choice') === 'other' ? 'selected' : '' ?>>ไม่พบหมู่บ้านในรายการ / กรอกเอง</option></select>
<div id="villageHelp" class="form-text">เลือกหมู่บ้านแล้วระบบจะเติมเลขหมู่ให้</div>
<div id="villageManual"><label for="addressVillage" class="form-label mt-2">ชื่อหมู่บ้านที่ต้องการระบุเอง</label><input id="addressVillage" name="village_name" maxlength="200" class="form-control" value="<?= $field('village_name') ?>" placeholder="กรอกชื่อหมู่บ้าน"></div></div>
<script type="application/json" id="registrationVillageData"><?= json_encode(registration_villages(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
