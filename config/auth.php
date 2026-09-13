<?php
require_once __DIR__ . '/app.php';

function check_login($allowed_roles = []): array
{
    global $conn;
    $roles = $allowed_roles ?: ['user', 'staff', 'admin'];
    return require_roles($conn, $roles, '../config/login.php');
}

