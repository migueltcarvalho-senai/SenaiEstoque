<?php
/**
 * Roteador para o servidor embutido do PHP (desenvolvimento local):
 *     php -S localhost:8080 router.php
 *
 * Reproduz as proteções do .htaccess, que o servidor embutido ignora.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = basename($path);

$blocked = preg_match('/(^\.|\.(sql|md|txt|ps1|rules|log|ini|lock|example|json)$|^(auth|config|estoque|schema|router)\.php$)/i', $file)
    || strpos($path, '/.') !== false;

if ($blocked) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não encontrado';
    return true;
}

if ($path === '/' && is_file(__DIR__ . '/index.php')) {
    require __DIR__ . '/index.php';
    return true;
}

return false; // deixa o servidor embutido servir o arquivo normalmente
