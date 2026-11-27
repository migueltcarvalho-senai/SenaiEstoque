<?php
/**
 * Encerra a sessão (somente via POST com token CSRF).
 */

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isValidCsrf($_POST['csrf_token'] ?? null)) {
    logoutUser();
    header('Location: login.php?saiu=1');
    exit;
}

header('Location: index.php');
exit;
