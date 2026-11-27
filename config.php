<?php
/**
 * Configuração do sistema e conexão com o MySQL
 * Sistema de Gestão de Estoque e Endereçamento 2D - SENAI
 *
 * As credenciais ficam no arquivo .env (fora do controle de versão).
 * Copie .env.example para .env e ajuste os valores.
 * Variáveis de ambiente reais do servidor têm prioridade sobre o .env.
 */

/**
 * Lê pares CHAVE=valor de um arquivo .env.
 * Não usa putenv(): hospedagens gratuitas (ex.: InfinityFree) costumam desativá-lo.
 */
function loadEnvFile(string $path): array {
    $vars = [];
    if (!is_readable($path)) return $vars;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line, " \t\r\n\0\x0B\xEF\xBB\xBF");
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) continue;
        $value = trim($m[2]);
        $quoted = strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"));
        if ($quoted) $value = substr($value, 1, -1);
        $vars[$m[1]] = $value;
    }
    return $vars;
}

/** Variável de ambiente real do servidor > arquivo .env > valor padrão. */
function env(string $key, string $default = ''): string {
    static $fileVars = null;
    if ($fileVars === null) $fileVars = loadEnvFile(__DIR__ . '/.env');
    $real = function_exists('getenv') ? getenv($key) : false;
    if ($real !== false && $real !== '') return $real;
    return $fileVars[$key] ?? $default;
}

date_default_timezone_set(env('APP_TIMEZONE', 'America/Sao_Paulo'));

define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'senai_estoque_2d'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

/**
 * Retorna uma instância PDO configurada.
 * Lança PDOException em caso de falha — quem chama decide como apresentar o erro.
 */
function getDbConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Mesmo fuso do PHP para NOW()/TIMESTAMP (não é crítico se a hospedagem recusar)
        try {
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            error_log('[senai-estoque] Não foi possível ajustar o fuso do MySQL: ' . $e->getMessage());
        }
    }
    return $pdo;
}
