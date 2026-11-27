<?php
/**
 * Autenticação por usuário e senha (sessão PHP)
 * Sistema de Gestão de Estoque e Endereçamento 2D - SENAI
 *
 * - Senhas com password_hash() (bcrypt/argon conforme o PHP).
 * - Sessão com cookie HttpOnly + SameSite=Lax, ID regenerado no login e expiração por inatividade.
 * - Token CSRF obrigatório em todo POST (formulários e API).
 * - Bloqueio temporário após várias tentativas de login erradas.
 * - Perfis: "admin" (gestão completa e usuários) e "operador" (movimentações do dia a dia).
 */

require_once __DIR__ . '/config.php';

const ROLE_ADMIN    = 'admin';
const ROLE_OPERADOR = 'operador';
const ROLES         = [ROLE_ADMIN, ROLE_OPERADOR];

const SESSION_IDLE_TIMEOUT = 8 * 3600; // encerra a sessão após 8h sem uso
const LOGIN_MAX_ATTEMPTS   = 5;        // tentativas erradas antes do bloqueio
const LOGIN_LOCK_MINUTES   = 15;       // duração do bloqueio
const PASSWORD_MIN_LENGTH  = 8;
const PASSWORD_MAX_BYTES   = 72;       // limite do bcrypt

/** Erro "esperado" com mensagem segura para exibir ao usuário. */
class AppException extends RuntimeException {
    public int $httpStatus;
    public function __construct(string $message, int $httpStatus = 400) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

// -----------------------------------------------------------------------------
// Sessão e CSRF
// -----------------------------------------------------------------------------

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('SENAI_ESTOQUE_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function isValidCsrf(?string $token): bool {
    startSecureSession();
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// -----------------------------------------------------------------------------
// Usuário atual
// -----------------------------------------------------------------------------

/**
 * Retorna o usuário logado (relido do banco a cada requisição, para que
 * desativações e mudanças de perfil valham imediatamente) ou null.
 */
function currentUser(): ?array {
    static $resolved = false;
    static $user = null;
    if ($resolved) return $user;
    $resolved = true;

    startSecureSession();
    if (empty($_SESSION['user_id'])) return null;

    if (time() - (int)($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_TIMEOUT) {
        logoutUser();
        return null;
    }

    $stmt = getDbConnection()->prepare(
        'SELECT `id`, `username`, `display_name`, `role`, `active`, `must_change_password`
           FROM `users` WHERE `id` = ?'
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !(int)$row['active']) {
        logoutUser();
        return null;
    }

    $_SESSION['last_activity'] = time();
    $user = [
        'id'                 => (int)$row['id'],
        'username'           => (string)$row['username'],
        'displayName'        => (string)$row['display_name'],
        'role'               => (string)$row['role'],
        'mustChangePassword' => (bool)$row['must_change_password'],
    ];
    return $user;
}

function isAdmin(?array $user): bool {
    return $user !== null && $user['role'] === ROLE_ADMIN;
}

/** Para páginas HTML: redireciona ao login se não houver sessão válida. */
function requirePageUser(): array {
    $user = currentUser();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    if ($user['mustChangePassword']) {
        header('Location: login.php?acao=trocar-senha');
        exit;
    }
    return $user;
}

// -----------------------------------------------------------------------------
// Login / logout
// -----------------------------------------------------------------------------

function normalizeUsername(string $username): string {
    return strtolower(trim($username));
}

/**
 * Valida credenciais e abre a sessão. Retorna o usuário ou lança AppException
 * com mensagem amigável (sem revelar se o usuário existe).
 */
function attemptLogin(string $username, string $password): array {
    $pdo = getDbConnection();
    $username = normalizeUsername($username);

    $stmt = $pdo->prepare('SELECT * FROM `users` WHERE `username` = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row) {
        // Hash fictício para manter o tempo de resposta parecido com o de um usuário real
        password_verify($password, '$2y$10$.vGA1O9wmRjrwAVXD98HNOgsNpDczlqm3Jq7KnEd1rVAGv3Fykk1a');
        throw new AppException('Usuário ou senha inválidos.', 401);
    }

    if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
        $minutes = max(1, (int)ceil((strtotime($row['locked_until']) - time()) / 60));
        throw new AppException("Muitas tentativas incorretas. Tente novamente em {$minutes} minuto(s).", 429);
    }

    if (!password_verify($password, $row['password_hash'])) {
        $attempts = (int)$row['failed_attempts'] + 1;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            $pdo->prepare('UPDATE `users` SET `failed_attempts` = 0, `locked_until` = ? WHERE `id` = ?')
                ->execute([$lockedUntil, $row['id']]);
            throw new AppException('Muitas tentativas incorretas. Acesso bloqueado por ' . LOGIN_LOCK_MINUTES . ' minutos.', 429);
        }
        $pdo->prepare('UPDATE `users` SET `failed_attempts` = ? WHERE `id` = ?')->execute([$attempts, $row['id']]);
        throw new AppException('Usuário ou senha inválidos.', 401);
    }

    if (!(int)$row['active']) {
        throw new AppException('Este usuário está desativado. Procure o administrador do sistema.', 403);
    }

    // Atualiza o hash se o algoritmo padrão do PHP mudou
    $newHash = password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)
        ? password_hash($password, PASSWORD_DEFAULT)
        : $row['password_hash'];

    $pdo->prepare('UPDATE `users` SET `failed_attempts` = 0, `locked_until` = NULL, `last_login_at` = NOW(), `password_hash` = ? WHERE `id` = ?')
        ->execute([$newHash, $row['id']]);

    startSecureSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return $row;
}

function logoutUser(): void {
    startSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

// -----------------------------------------------------------------------------
// Validação e gestão de usuários
// -----------------------------------------------------------------------------

function validateUsername(string $username): string {
    $username = normalizeUsername($username);
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        throw new AppException('O usuário deve ter de 3 a 32 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.');
    }
    return $username;
}

function validateDisplayName(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $len = mb_strlen($name);
    if ($len < 2 || $len > 100) {
        throw new AppException('Informe o nome completo (2 a 100 caracteres).');
    }
    return $name;
}

function validatePassword(string $password): void {
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        throw new AppException('A senha deve ter pelo menos ' . PASSWORD_MIN_LENGTH . ' caracteres.');
    }
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        throw new AppException('A senha é longa demais (máximo de ' . PASSWORD_MAX_BYTES . ' caracteres).');
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new AppException('A senha deve conter letras e números.');
    }
}

function validateRole(string $role): string {
    if (!in_array($role, ROLES, true)) {
        throw new AppException('Perfil inválido.');
    }
    return $role;
}

/** Senha temporária legível (sem caracteres ambíguos), sempre com letras e números. */
function generateTempPassword(int $length = 10): string {
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $all     = $letters . $digits;
    $chars   = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

function countUsers(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
}

function createUser(PDO $pdo, string $username, string $displayName, string $password, string $role, bool $mustChange): int {
    $username    = validateUsername($username);
    $displayName = validateDisplayName($displayName);
    $role        = validateRole($role);
    validatePassword($password);

    $exists = $pdo->prepare('SELECT 1 FROM `users` WHERE `username` = ?');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        throw new AppException("O usuário \"{$username}\" já existe.", 409);
    }

    $pdo->prepare('INSERT INTO `users` (`username`, `display_name`, `password_hash`, `role`, `must_change_password`) VALUES (?, ?, ?, ?, ?)')
        ->execute([$username, $displayName, password_hash($password, PASSWORD_DEFAULT), $role, $mustChange ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

/** Troca a própria senha exigindo a senha atual. */
function changeOwnPassword(PDO $pdo, int $userId, string $currentPassword, string $newPassword): void {
    $stmt = $pdo->prepare('SELECT `password_hash` FROM `users` WHERE `id` = ?');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($currentPassword, $hash)) {
        throw new AppException('A senha atual está incorreta.', 400);
    }
    if (hash_equals($currentPassword, $newPassword)) {
        throw new AppException('A nova senha deve ser diferente da atual.');
    }
    validatePassword($newPassword);
    $pdo->prepare('UPDATE `users` SET `password_hash` = ?, `must_change_password` = 0 WHERE `id` = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    // Novo ID de sessão após troca de credencial
    startSecureSession();
    session_regenerate_id(true);
}

function countActiveAdmins(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `active` = 1")->fetchColumn();
}

