<?php
/**
 * Tela de acesso: login, configuração inicial (1º administrador) e troca de senha obrigatória.
 * Sistema de Gestão de Estoque e Endereçamento 2D - SENAI
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/schema.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$error   = '';
$notice  = '';
$mode    = 'login'; // login | setup | change | dberror
$oldUser = '';
$oldName = '';

try {
    $pdo = getDbConnection();
    ensureSchema($pdo);

    $user = currentUser();
    $hasUsers = countUsers($pdo) > 0;

    if (!$hasUsers) {
        $mode = 'setup';
    } elseif ($user && $user['mustChangePassword']) {
        $mode = 'change';
    } elseif ($user) {
        header('Location: index.php');
        exit;
    }

    if (isset($_GET['expirada'])) $notice = 'Sua sessão expirou. Entre novamente para continuar.';
    if (isset($_GET['saiu']))     $notice = 'Você saiu do sistema com segurança.';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
            throw new AppException('O formulário expirou. Tente novamente.');
        }

        if ($mode === 'setup') {
            $oldUser = (string)($_POST['username'] ?? '');
            $oldName = (string)($_POST['display_name'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            if ($password !== (string)($_POST['password_confirm'] ?? '')) {
                throw new AppException('As senhas não conferem.');
            }
            // Reconfere dentro do POST para evitar dois "primeiros administradores"
            if (countUsers($pdo) > 0) {
                throw new AppException('O administrador já foi criado. Faça login.');
            }
            createUser($pdo, $oldUser, $oldName, $password, ROLE_ADMIN, false);
            attemptLogin($oldUser, $password);
            header('Location: index.php');
            exit;
        }

        if ($mode === 'change') {
            $new = (string)($_POST['new_password'] ?? '');
            if ($new !== (string)($_POST['new_password_confirm'] ?? '')) {
                throw new AppException('As novas senhas não conferem.');
            }
            changeOwnPassword($pdo, $user['id'], (string)($_POST['current_password'] ?? ''), $new);
            header('Location: index.php');
            exit;
        }

        $oldUser = (string)($_POST['username'] ?? '');
        $row = attemptLogin($oldUser, (string)($_POST['password'] ?? ''));
        header('Location: ' . ((int)$row['must_change_password'] ? 'login.php?acao=trocar-senha' : 'index.php'));
        exit;
    }
} catch (AppException $e) {
    $error = $e->getMessage();
} catch (PDOException $e) {
    error_log('[senai-estoque] Erro de banco na tela de login: ' . $e->getMessage());
    $mode  = 'dberror';
    $error = 'Não foi possível conectar ao banco de dados MySQL. Verifique se o MySQL está ligado, se o banco "' . DB_NAME . '" foi criado (database.sql) e as credenciais no arquivo .env.';
} catch (Throwable $e) {
    error_log('[senai-estoque] Erro interno na tela de login: ' . $e->getMessage());
    $error = 'Erro interno no servidor. Tente novamente em instantes.';
}

$csrf = $mode === 'dberror' ? '' : csrfToken();

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$titles = [
    'login'   => ['Entrar no sistema', 'Use o usuário e a senha fornecidos pelo administrador.'],
    'setup'   => ['Configuração inicial', 'Nenhum usuário cadastrado ainda. Crie a conta do administrador do sistema.'],
    'change'  => ['Defina sua nova senha', 'Você entrou com uma senha temporária. Crie uma senha pessoal para continuar.'],
    'dberror' => ['Banco de dados indisponível', 'O sistema não conseguiu acessar o MySQL.'],
];
[$title, $subtitle] = $titles[$mode];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso — SENAI Estoque 2D</title>
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
    </script>
</head>
<body class="min-h-screen bg-[#F4F6F9] font-sans antialiased text-slate-800 flex flex-col">
    <header class="bg-[#0F294A] border-b-4 border-[#E30613]">
        <div class="max-w-md mx-auto px-4 py-4 flex items-center gap-3">
            <svg viewBox="0 0 380 92" class="h-9 w-auto rounded-md" role="img" aria-label="Logo SENAI">
                <rect width="380" height="92" fill="#FF0000"/>
                <g transform="translate(190, 48) skewX(-12) scale(1.06, 1)">
                    <text x="0" y="27" text-anchor="middle" fill="#FFFFFF" font-family="'Arial Black', 'Inter', Impact, sans-serif" font-weight="900" font-size="78" letter-spacing="2">SENAI</text>
                </g>
            </svg>
            <div class="border-l border-white/20 pl-3">
                <p class="text-sm font-extrabold text-white leading-tight">Controle de Estoque e Validades</p>
                <p class="text-xs text-slate-300">Endereçamento 2D</p>
            </div>
        </div>
    </header>

    <main class="flex-1 flex items-start sm:items-center justify-center px-4 py-8">
        <div class="w-full max-w-md bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
            <h1 class="text-xl font-extrabold text-slate-900"><?= e($title) ?></h1>
            <p class="text-sm text-slate-600 mt-1"><?= e($subtitle) ?></p>

            <?php if ($notice && !$error): ?>
                <div class="mt-5 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-900" role="status"><?= e($notice) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($mode === 'dberror'): ?>
                <a href="login.php" class="mt-6 inline-flex w-full justify-center rounded-xl bg-[#0F294A] hover:bg-[#183B66] px-4 py-3 text-sm font-bold text-white">Tentar novamente</a>
            <?php else: ?>
            <form method="post" class="mt-6 space-y-4" novalidate data-auth-form>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                <?php if ($mode === 'setup'): ?>
                    <div>
                        <label for="display_name" class="block text-sm font-bold text-slate-800 mb-1">Nome completo</label>
                        <input id="display_name" name="display_name" type="text" required minlength="2" maxlength="100" autocomplete="name" value="<?= e($oldName) ?>"
                               class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#0F294A]">
                    </div>
                <?php endif; ?>

                <?php if ($mode === 'login' || $mode === 'setup'): ?>
                    <div>
                        <label for="username" class="block text-sm font-bold text-slate-800 mb-1">Usuário</label>
                        <input id="username" name="username" type="text" required minlength="3" maxlength="32" autocomplete="username" autocapitalize="none" spellcheck="false"
                               <?= $mode === 'setup' ? 'pattern="[a-zA-Z0-9._\-]{3,32}"' : '' ?>
                               value="<?= e($oldUser) ?>" autofocus
                               class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#0F294A]">
                        <?php if ($mode === 'setup'): ?>
                            <p class="mt-1 text-xs text-slate-600">3 a 32 caracteres: letras, números, ponto, hífen ou sublinhado. Ex.: <code>joao.silva</code></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($mode === 'change'): ?>
                    <div>
                        <label for="current_password" class="block text-sm font-bold text-slate-800 mb-1">Senha temporária (atual)</label>
                        <input id="current_password" name="current_password" type="password" required autocomplete="current-password" autofocus
                               class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#0F294A]">
                    </div>
                <?php endif; ?>

                <div>
                    <label for="pwd-main" class="block text-sm font-bold text-slate-800 mb-1"><?= $mode === 'login' ? 'Senha' : 'Nova senha' ?></label>
                    <div class="relative">
                        <input id="pwd-main" name="<?= $mode === 'change' ? 'new_password' : 'password' ?>" type="password" required
                               <?= $mode === 'login' ? 'autocomplete="current-password"' : 'autocomplete="new-password" minlength="' . PASSWORD_MIN_LENGTH . '"' ?>
                               class="w-full rounded-xl border border-slate-300 bg-white pl-3.5 pr-20 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#0F294A]">
                        <button type="button" data-toggle-password="pwd-main" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg px-2.5 py-1.5 text-xs font-bold text-[#0F294A] hover:bg-slate-100">Mostrar</button>
                    </div>
                    <?php if ($mode !== 'login'): ?>
                        <p class="mt-1 text-xs text-slate-600">Mínimo de <?= PASSWORD_MIN_LENGTH ?> caracteres, com letras e números.</p>
                    <?php endif; ?>
                </div>

                <?php if ($mode !== 'login'): ?>
                    <div>
                        <label for="pwd-confirm" class="block text-sm font-bold text-slate-800 mb-1">Confirme a nova senha</label>
                        <input id="pwd-confirm" name="<?= $mode === 'change' ? 'new_password_confirm' : 'password_confirm' ?>" type="password" required autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>"
                               class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#0F294A]">
                    </div>
                <?php endif; ?>

                <p data-form-error class="hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800" role="alert"></p>

                <button type="submit" data-submit class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-[#E30613] hover:bg-[#C80510] disabled:opacity-70 px-4 py-3 text-sm font-bold text-white shadow-sm transition-colors">
                    <svg data-spinner class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
                    <span><?= ['login' => 'Entrar', 'setup' => 'Criar administrador e entrar', 'change' => 'Salvar nova senha'][$mode] ?></span>
                </button>
            </form>

            <?php if ($mode === 'login'): ?>
                <p class="mt-5 text-xs text-slate-600 text-center">Esqueceu a senha? Peça ao administrador do sistema para gerar uma senha temporária.</p>
            <?php elseif ($mode === 'change'): ?>
                <form method="post" action="logout.php" class="mt-5 text-center">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <button type="submit" class="text-xs font-semibold text-slate-600 hover:underline">Sair e voltar ao login</button>
                </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Mostrar/ocultar senha
        document.querySelectorAll('[data-toggle-password]').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.togglePassword);
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.textContent = show ? 'Ocultar' : 'Mostrar';
            });
        });

        // Validação no cliente (o servidor valida tudo novamente)
        const form = document.querySelector('[data-auth-form]');
        if (form) {
            form.addEventListener('submit', (ev) => {
                const errBox = form.querySelector('[data-form-error]');
                const fail = (msg, field) => {
                    ev.preventDefault();
                    errBox.textContent = msg;
                    errBox.classList.remove('hidden');
                    if (field) field.focus();
                };
                errBox.classList.add('hidden');

                for (const field of form.querySelectorAll('input[required]')) {
                    if (!field.value.trim()) {
                        const label = form.querySelector(`label[for="${field.id}"]`);
                        return fail(`Preencha o campo "${label ? label.textContent.trim() : field.name}".`, field);
                    }
                }
                const user = form.querySelector('#username');
                if (user && user.pattern && !new RegExp(`^(?:${user.pattern})$`).test(user.value.trim())) {
                    return fail('Usuário inválido: use de 3 a 32 letras, números, ponto, hífen ou sublinhado.', user);
                }
                const pwd = form.querySelector('#pwd-main');
                const confirm = form.querySelector('#pwd-confirm');
                if (confirm) {
                    if (pwd.value.length < <?= PASSWORD_MIN_LENGTH ?>) return fail('A nova senha deve ter pelo menos <?= PASSWORD_MIN_LENGTH ?> caracteres.', pwd);
                    if (!/[A-Za-z]/.test(pwd.value) || !/\d/.test(pwd.value)) return fail('A nova senha deve conter letras e números.', pwd);
                    if (pwd.value !== confirm.value) return fail('As senhas não conferem.', confirm);
                }

                const btn = form.querySelector('[data-submit]');
                btn.disabled = true;
                btn.querySelector('[data-spinner]').classList.remove('hidden');
            });
        }
    </script>
</body>
</html>
