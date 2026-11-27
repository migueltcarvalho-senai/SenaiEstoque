<?php
/**
 * API RESTful JSON
 * Sistema de Gestão de Estoque e Endereçamento 2D - SENAI
 *
 * - Todas as ações (exceto "health") exigem sessão autenticada.
 * - Ações de escrita exigem POST + cabeçalho X-CSRF-Token.
 * - Cada escrita é uma operação de negócio validada no servidor (estoque.php)
 *   e devolve o estado atualizado, que o cliente apenas exibe.
 */

require_once __DIR__ . '/estoque.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Ações somente leitura (GET)
const READ_ACTIONS = ['health', 'get_all', 'me', 'list_users'];

// Operações de estoque: ação => [função, somente admin?]
const STOCK_OPERATIONS = [
    'product_save'       => ['opProductSave', false],
    'product_delete'     => ['opProductDelete', true],
    'stock_allocate'     => ['opAllocate', false],
    'stock_withdraw'     => ['opWithdraw', false],
    'stock_transfer'     => ['opTransfer', false],
    'slot_clear'         => ['opSlotClear', false],
    'expiry_update'      => ['opExpiryUpdate', false],
    'quick_adjust'       => ['opQuickAdjust', false],
    'import_items'       => ['opImportItems', false], // modo "substituir" é verificado dentro da operação
    'custom_slot_add'    => ['opCustomSlotAdd', true],
    'custom_slot_remove' => ['opCustomSlotRemove', true],
    'slots_reset_all'    => ['opSlotsResetAll', true],
    'backup_restore'     => ['opBackupRestore', true],
    'clear_history'      => ['opClearHistory', true],
    'reset_all'          => ['opResetAll', true],
];

// Ações de usuários restritas ao administrador
const ADMIN_USER_ACTIONS = ['list_users', 'create_user', 'update_user', 'reset_password'];

$action = (string)($_GET['action'] ?? 'get_all');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    try {
        $pdo = getDbConnection();
    } catch (PDOException $e) {
        error_log('[senai-estoque] Falha de conexão MySQL: ' . $e->getMessage());
        throw new AppException('Falha ao conectar no banco de dados MySQL. Verifique se o MySQL está ligado e as credenciais no arquivo .env.', 503);
    }

    if ($action === 'health') {
        respond(['success' => true, 'status' => 'online']);
    }

    $isRead = in_array($action, READ_ACTIONS, true);
    if ($isRead !== ($method === 'GET')) {
        throw new AppException('Método não permitido.', 405);
    }

    $user = currentUser();
    if (!$user) {
        throw new AppException('Sua sessão expirou. Faça login novamente.', 401);
    }
    if (!$isRead && !isValidCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        throw new AppException('Token de segurança inválido. Recarregue a página e tente novamente.', 403);
    }
    if ($user['mustChangePassword'] && !in_array($action, ['me', 'change_password'], true)) {
        throw new AppException('Troque sua senha temporária antes de continuar.', 403);
    }
    $adminOnly = in_array($action, ADMIN_USER_ACTIONS, true) || (STOCK_OPERATIONS[$action][1] ?? false);
    if ($adminOnly && !isAdmin($user)) {
        throw new AppException('Ação permitida somente para administradores.', 403);
    }

    // Libera o lock da sessão cedo para não travar requisições paralelas.
    // A troca de senha ainda precisa da sessão aberta para regenerar o ID.
    if ($action !== 'change_password') {
        session_write_close();
    }

    $input = $isRead ? [] : readJsonBody();

    if (isset(STOCK_OPERATIONS[$action])) {
        $fn = STOCK_OPERATIONS[$action][0];
        $message = $fn($pdo, $user, $input);
        respond(['success' => true, 'message' => $message, 'data' => buildState($pdo)]);
    }

    switch ($action) {
        case 'get_all':
            respond(['success' => true, 'data' => buildState($pdo)]);
            break;
        case 'me':
            respond(['success' => true, 'user' => $user]);
            break;
        case 'change_password':
            changeOwnPassword($pdo, $user['id'], (string)($input['currentPassword'] ?? ''), (string)($input['newPassword'] ?? ''));
            respond(['success' => true, 'message' => 'Senha alterada com sucesso.']);
            break;
        case 'list_users':     handleListUsers($pdo); break;
        case 'create_user':    handleCreateUser($pdo, $input); break;
        case 'update_user':    handleUpdateUser($pdo, $user, $input); break;
        case 'reset_password': handleResetPassword($pdo, $user, $input); break;
        default:
            throw new AppException('Ação desconhecida.', 400);
    }
} catch (AppException $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[senai-estoque] Erro interno na ação ' . $action . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno no servidor. Tente novamente; se persistir, avise o administrador.'], JSON_UNESCAPED_UNICODE);
}

// -----------------------------------------------------------------------------
// Utilitários
// -----------------------------------------------------------------------------

function respond(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    if (strlen($raw) > 8 * 1024 * 1024) {
        throw new AppException('Dados enviados grandes demais.', 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new AppException('Corpo JSON inválido.', 400);
    }
    return $data;
}

// -----------------------------------------------------------------------------
// Usuários (somente administradores, exceto troca da própria senha)
// -----------------------------------------------------------------------------

function fetchUserRow(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM `users` WHERE `id` = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new AppException('Usuário não encontrado.', 404);
    return $row;
}

function handleListUsers(PDO $pdo): void {
    $rows = $pdo->query("
        SELECT `id`, `username`, `display_name`, `role`, `active`, `must_change_password`, `locked_until`, `last_login_at`, `created_at`
          FROM `users` ORDER BY `active` DESC, `display_name` ASC
    ")->fetchAll();

    $users = array_map(function ($u) {
        return [
            'id'                 => (int)$u['id'],
            'username'           => (string)$u['username'],
            'displayName'        => (string)$u['display_name'],
            'role'               => (string)$u['role'],
            'active'             => (bool)$u['active'],
            'mustChangePassword' => (bool)$u['must_change_password'],
            'locked'             => !empty($u['locked_until']) && strtotime($u['locked_until']) > time(),
            'lastLoginAt'        => (string)($u['last_login_at'] ?? ''),
            'createdAt'          => (string)($u['created_at'] ?? ''),
        ];
    }, $rows);

    respond(['success' => true, 'users' => $users]);
}

function handleCreateUser(PDO $pdo, array $input): void {
    $tempPassword = generateTempPassword();
    $username = validateUsername((string)($input['username'] ?? ''));
    $id = createUser($pdo, $username, (string)($input['displayName'] ?? ''), $tempPassword, (string)($input['role'] ?? ROLE_OPERADOR), true);
    respond(['success' => true, 'id' => $id, 'username' => $username, 'tempPassword' => $tempPassword]);
}

function handleUpdateUser(PDO $pdo, array $me, array $input): void {
    $id  = (int)($input['id'] ?? 0);
    $row = fetchUserRow($pdo, $id);

    $displayName = array_key_exists('displayName', $input) ? validateDisplayName((string)$input['displayName']) : $row['display_name'];
    $role        = array_key_exists('role', $input) ? validateRole((string)$input['role']) : $row['role'];
    $active      = array_key_exists('active', $input) ? (bool)$input['active'] : (bool)$row['active'];

    $losesAdmin = $row['role'] === ROLE_ADMIN && (int)$row['active'] === 1 && ($role !== ROLE_ADMIN || !$active);
    if ($losesAdmin) {
        if ($id === $me['id']) {
            throw new AppException('Você não pode remover o seu próprio acesso de administrador.');
        }
        if (countActiveAdmins($pdo) <= 1) {
            throw new AppException('O sistema precisa de pelo menos um administrador ativo.');
        }
    }

    $pdo->prepare('UPDATE `users` SET `display_name` = ?, `role` = ?, `active` = ? WHERE `id` = ?')
        ->execute([$displayName, $role, $active ? 1 : 0, $id]);

    respond(['success' => true, 'message' => 'Usuário atualizado.']);
}

function handleResetPassword(PDO $pdo, array $me, array $input): void {
    $id = (int)($input['id'] ?? 0);
    $row = fetchUserRow($pdo, $id);
    if ($id === $me['id']) {
        throw new AppException('Para trocar a sua própria senha, use "Alterar senha" no menu do usuário.');
    }
    $tempPassword = generateTempPassword();
    $pdo->prepare('UPDATE `users` SET `password_hash` = ?, `must_change_password` = 1, `failed_attempts` = 0, `locked_until` = NULL WHERE `id` = ?')
        ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $id]);

    respond(['success' => true, 'username' => $row['username'], 'tempPassword' => $tempPassword]);
}
