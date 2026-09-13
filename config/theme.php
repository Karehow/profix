<?php
// Shared head assets; paths are relative to the public page, including auth views.
$themeAssetBase = '../assets/';
// The landing page may be served from a differently named installation directory.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__DIR__ . '/../index.php')) $themeAssetBase = 'assets/';
?>
<link rel="stylesheet" href="<?= $themeAssetBase ?>css/dark-mode.css">
<script src="<?= $themeAssetBase ?>js/theme.js?v=2"></script>
<link rel="stylesheet" href="<?= $themeAssetBase ?>../config/branding_css.php">
<?php if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)): ?>
<link rel="stylesheet" href="<?= $themeAssetBase ?>css/management-theme.css?v=2">
<link rel="stylesheet" href="<?= $themeAssetBase ?>css/notification-popup.css?v=2">
<?php endif; ?>
