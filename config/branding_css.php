<?php
require_once __DIR__ . '/branding.php';
header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-cache');
$branding = branding_settings();
if (!empty($branding['banner'])): ?>
:root { --site-banner: url('../<?= $branding['banner'] ?>'); }
<?php endif; ?>
<?php if (!empty($branding['logo'])): ?>
html body :is(.dashboard-brand-mark,.management-brand-mark,.ui-brand-icon,.auth-brand-mark,.public-brand > svg) {
    background: url('../<?= $branding['logo'] ?>') center / contain no-repeat !important;
    border-radius: 0; font-size: 0; flex-shrink: 0;
}
html body :is(.dashboard-brand-mark,.management-brand-mark) { display:block; width:48px; height:48px; }
html body :is(.dashboard-brand-mark,.management-brand-mark,.ui-brand-icon) > svg,
html body .public-brand > svg > use { visibility:hidden; }
@media(max-width:700px) { html body :is(.dashboard-brand-mark,.management-brand-mark) { width:36px; height:36px; } }
<?php endif; ?>
