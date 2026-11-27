<?php
/**
 * Regras de negócio do estoque — operações validadas no servidor
 * Sistema de Gestão de Estoque e Endereçamento 2D - SENAI
 *
 * Cada operação:
 *  - valida todos os campos recebidos (tipos, limites, datas, existência);
 *  - roda em transação sob uma trava global de escrita (sem "último a salvar vence");
 *  - registra a movimentação no histórico com o usuário da sessão;
 *  - nunca deixa saldo negativo nem cria estoque do nada.
 */

require_once __DIR__ . '/auth.php';

// Posições fixas da planta baixa, na mesma ordem de BASE_FLOORPLAN em index.php
// (esquerda, fundo, centro, direita). Manter os dois em sincronia.
const BASE_SLOT_CODES = ['M1', 'M2', 'M3', 'G1', 'E1', 'E2', 'C1', 'F1', 'F2', 'I1', 'P1', 'D1', 'D2', 'D3', 'A1'];

const MAX_QTY            = 1000000;
const MAX_IMPORT_ITEMS   = 5000;
const ID_PATTERN         = '/^[A-Za-z0-9_-]{1,64}$/';
const SKU_PATTERN        = '/^[A-Z0-9][A-Z0-9._\/-]{0,31}$/';
const CUSTOM_SLOT_REGEX  = '/^[A-Z0-9_-]{1,6}$/';
const STORED_SLOT_REGEX  = '/^[A-Z0-9_-]{1,16}$/';

// -----------------------------------------------------------------------------
// Validação de entrada
// -----------------------------------------------------------------------------

function inputString(array $in, string $key, string $label, int $maxLen, bool $required = false, int $minLen = 0): string {
    $raw = $in[$key] ?? '';
    if (!is_scalar($raw)) {
        throw new AppException("Campo \"{$label}\" inválido.");
    }
    $value = trim((string)$raw);
    if ($required && $value === '') {
        throw new AppException("Preencha o campo \"{$label}\".");
    }
    $len = mb_strlen($value);
    if ($value !== '' && $len < $minLen) {
        throw new AppException("O campo \"{$label}\" deve ter pelo menos {$minLen} caracteres.");
    }
    if ($len > $maxLen) {
        throw new AppException("O campo \"{$label}\" aceita no máximo {$maxLen} caracteres.");
    }
    return $value;
}

function inputInt(array $in, string $key, string $label, int $min, int $max): int {
    $raw = $in[$key] ?? null;
    if (is_string($raw)) $raw = trim($raw);
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        throw new AppException("O campo \"{$label}\" deve ser um número inteiro.");
    }
    if ($value < $min || $value > $max) {
        throw new AppException("O campo \"{$label}\" deve estar entre {$min} e " . number_format($max, 0, ',', '.') . '.');
    }
    return $value;
}

function isValidIsoDate(string $value): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return false;
    $y = (int)$m[1];
    return $y >= 1990 && $y <= 2200 && checkdate((int)$m[2], (int)$m[3], $y);
}

function inputDate(array $in, string $key, string $label, bool $required = false): string {
    $value = inputString($in, $key, $label, 10, $required);
    if ($value !== '' && !isValidIsoDate($value)) {
        throw new AppException("Data inválida em \"{$label}\". Use o formato AAAA-MM-DD.");
    }
    return $value;
}

function inputId(array $in, string $key, string $label): string {
    $value = inputString($in, $key, $label, 64, true);
    if (!preg_match(ID_PATTERN, $value)) {
        throw new AppException("Identificador inválido em \"{$label}\".");
    }
    return $value;
}

function inputSlotCode(array $in, string $key, string $label): string {
    $value = strtoupper(inputString($in, $key, $label, 16, true));
    if (!preg_match(STORED_SLOT_REGEX, $value)) {
        throw new AppException("Código de posição inválido em \"{$label}\".");
    }
    return $value;
}

function normalizeSku(string $sku): string {
    return strtoupper(trim($sku));
}

function formatDateBR(string $iso): string {
    return isValidIsoDate($iso) ? implode('/', array_reverse(explode('-', $iso))) : 'sem validade';
}

function cut(string $value, int $max): string {
    return mb_substr($value, 0, $max);
}

// -----------------------------------------------------------------------------
// Infraestrutura: trava de escrita, histórico e leitura do estado
// -----------------------------------------------------------------------------

/**
 * Executa $fn em transação, serializando todas as escritas do sistema.
 * Simples e suficiente para o volume de um almoxarifado escolar.
 */
function runWrite(PDO $pdo, callable $fn) {
    $lockName = substr('senai_estoque_write_' . DB_NAME, 0, 64);
    $locked = false;
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$lockName]);
        $got = $stmt->fetchColumn();
        if ($got !== null && (int)$got !== 1) {
            throw new AppException('O sistema está ocupado processando outra operação. Tente novamente.', 503);
        }
        $locked = (int)$got === 1;
    } catch (PDOException $e) {
        // Hospedagem sem suporte a GET_LOCK: segue apenas com a transação
        error_log('[senai-estoque] GET_LOCK indisponível: ' . $e->getMessage());
    }
    try {
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } finally {
        if ($locked) {
            try {
                $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            } catch (PDOException $e) {
                error_log('[senai-estoque] RELEASE_LOCK falhou: ' . $e->getMessage());
            }
        }
    }
}

function logHistory(PDO $pdo, array $user, string $type, string $productName, string $slotCode, string $qtyText, string $note): void {
    $pdo->prepare("
        INSERT INTO `history` (`id`, `timestamp`, `type`, `product_name`, `slot_code`, `qty_text`, `note`, `user_id`, `username`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        'hist-' . bin2hex(random_bytes(8)),
        date('d/m/Y H:i:s'),
        cut($type, 32),
        cut($productName, 255),
        cut($slotCode, 64),
        cut($qtyText, 64),
        $note === '' ? '-' : $note,
        $user['id'],
        $user['username'],
    ]);
}

function newId(string $prefix): string {
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function findProduct(PDO $pdo, string $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM `products` WHERE `id` = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function requireProduct(PDO $pdo, string $id): array {
    $p = findProduct($pdo, $id);
    if (!$p) {
        throw new AppException('Produto não encontrado. Ele pode ter sido excluído por outro usuário.', 404);
    }
    return $p;
}

function findSlot(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare('SELECT * FROM `slots` WHERE `slot_code` = ? AND `qty` > 0');
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function requireOccupiedSlot(PDO $pdo, string $code): array {
    $slot = findSlot($pdo, $code);
    if (!$slot) {
        throw new AppException("A posição {$code} está vazia. Os dados podem ter sido alterados por outro usuário.", 409);
    }
    return $slot;
}

/** Todas as posições válidas (fixas + extras), na ordem de exibição do mapa. */
function allSlotCodes(PDO $pdo): array {
    $custom = $pdo->query('SELECT `code` FROM `custom_slots` ORDER BY `created_at`, `code`')->fetchAll(PDO::FETCH_COLUMN);
    return array_merge(BASE_SLOT_CODES, array_map('strval', $custom));
}

function requireValidSlotCode(PDO $pdo, string $code): void {
    if (!in_array($code, allSlotCodes($pdo), true)) {
        throw new AppException("A posição {$code} não existe no mapa.", 404);
    }
}

function productLabel(array $p): string {
    return (string)$p['name'];
}

/** Move o conteúdo de uma posição para o Estoque Geral (lote não alocado). */
function moveSlotToGeneral(PDO $pdo, array $user, array $slot, string $reason): void {
    $prod = findProduct($pdo, $slot['product_id']);
    $pdo->prepare('INSERT INTO `unallocated_lots` (`id`, `product_id`, `qty`, `entry_date`, `expiry_date`, `observations`) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            newId('lot'),
            $slot['product_id'],
            (int)$slot['qty'],
            $slot['entry_date'] ?: date('Y-m-d'),
            (string)($slot['expiry_date'] ?? ''),
            (string)($slot['observations'] ?: ($slot['lot'] ?? '')),
        ]);
    $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$slot['slot_code']]);
    if ($prod) {
        logHistory($pdo, $user, 'TRANSFERENCIA', productLabel($prod), $slot['slot_code'] . ' → Geral', $slot['qty'] . ' ' . $prod['unit'], $reason);
    }
}

function buildState(PDO $pdo): array {
    $products = array_map(function ($p) {
        return [
            'id'             => (string)$p['id'],
            'sku'            => (string)$p['sku'],
            'name'           => (string)$p['name'],
            'category'       => (string)$p['category'],
            'unit'           => (string)$p['unit'],
            'minStock'       => (int)$p['min_stock'],
            'maxStock'       => $p['max_stock'] !== null && (int)$p['max_stock'] > 0 ? (int)$p['max_stock'] : null,
            'defaultExpiry'  => (string)($p['default_expiry'] ?? ''),
            'storage'        => (string)($p['storage'] ?? ''),
            'flagPerecivel'  => (bool)$p['flag_perecivel'],
            'flagEpi'        => (bool)$p['flag_epi'],
            'flagControlado' => (bool)$p['flag_controlado'],
            'photo'          => (string)($p['photo'] ?? ''),
            'observations'   => (string)($p['observations'] ?? ''),
        ];
    }, $pdo->query('SELECT * FROM `products` ORDER BY `name` ASC')->fetchAll());

    $slots = [];
    foreach ($pdo->query('SELECT * FROM `slots` WHERE `qty` > 0')->fetchAll() as $s) {
        $slots[$s['slot_code']] = [
            'productId'    => (string)$s['product_id'],
            'qty'          => (int)$s['qty'],
            'entryDate'    => (string)($s['entry_date'] ?? ''),
            'expiryDate'   => (string)($s['expiry_date'] ?? ''),
            'lot'          => (string)($s['lot'] ?? ''),
            'observations' => (string)($s['observations'] ?? ''),
        ];
    }

    $unallocatedLots = array_map(function ($l) {
        return [
            'id'           => (string)$l['id'],
            'productId'    => (string)$l['product_id'],
            'qty'          => (int)$l['qty'],
            'entryDate'    => (string)($l['entry_date'] ?? ''),
            'expiryDate'   => (string)($l['expiry_date'] ?? ''),
            'observations' => (string)($l['observations'] ?? ''),
        ];
    }, $pdo->query('SELECT * FROM `unallocated_lots` WHERE `qty` > 0 ORDER BY `updated_at`, `id`')->fetchAll());

    $customSlots = array_map(function ($c) {
        return ['code' => (string)$c['code'], 'label' => (string)$c['label']];
    }, $pdo->query('SELECT * FROM `custom_slots` ORDER BY `created_at`, `code`')->fetchAll());

    $history = array_map(function ($h) {
        return [
            'id'          => (string)$h['id'],
            'timestamp'   => (string)$h['timestamp'],
            'type'        => (string)$h['type'],
            'productName' => (string)$h['product_name'],
            'slotCode'    => (string)$h['slot_code'],
            'qtyText'     => (string)$h['qty_text'],
            'note'        => (string)($h['note'] ?? '-'),
            'user'        => (string)($h['username'] ?? ''),
        ];
    }, $pdo->query('SELECT * FROM `history` ORDER BY `created_at` DESC, `id` DESC LIMIT 250')->fetchAll());

    return [
        'products'        => $products,
        'slots'           => (object)$slots,
        'unallocatedLots' => $unallocatedLots,
        'customSlots'     => $customSlots,
        'history'         => $history,
        'serverTime'      => date('Y-m-d H:i:s'),
    ];
}

// -----------------------------------------------------------------------------
// Produtos
// -----------------------------------------------------------------------------

/** Valida os campos de um produto (formulário, importação ou backup). */
function validateProductFields(array $in): array {
    $sku = normalizeSku(inputString($in, 'sku', 'SKU', 32, true));
    if (!preg_match(SKU_PATTERN, $sku)) {
        throw new AppException("SKU \"{$sku}\" inválido: use letras, números, ponto, hífen, barra ou sublinhado (até 32).");
    }

    $minStock = inputInt(['v' => $in['minStock'] ?? 0], 'v', 'Estoque mínimo', 0, MAX_QTY);
    $maxRaw   = $in['maxStock'] ?? null;
    $maxStock = null;
    if ($maxRaw !== null && $maxRaw !== '' && (string)$maxRaw !== '0') {
        $maxStock = inputInt(['v' => $maxRaw], 'v', 'Estoque máximo', 1, MAX_QTY);
        if ($maxStock < $minStock) {
            throw new AppException('O estoque máximo não pode ser menor que o estoque mínimo.');
        }
    }

    return [
        'sku'             => $sku,
        'name'            => inputString($in, 'name', 'Nome do produto', 255, true, 2),
        'category'        => inputString($in, 'category', 'Categoria', 64, true),
        'unit'            => inputString($in, 'unit', 'Unidade', 16, true),
        'min_stock'       => $minStock,
        'max_stock'       => $maxStock,
        'default_expiry'  => inputDate($in, 'defaultExpiry', 'Validade padrão'),
        'storage'         => inputString($in, 'storage', 'Armazenamento', 128),
        'flag_perecivel'  => !empty($in['flagPerecivel']) ? 1 : 0,
        'flag_epi'        => !empty($in['flagEpi']) ? 1 : 0,
        'flag_controlado' => !empty($in['flagControlado']) ? 1 : 0,
        'observations'    => inputString($in, 'observations', 'Observações', 2000),
    ];
}

function assertSkuAvailable(PDO $pdo, string $sku, string $exceptId = ''): void {
    $stmt = $pdo->prepare('SELECT `name` FROM `products` WHERE `sku` = ? AND `id` <> ? LIMIT 1');
    $stmt->execute([$sku, $exceptId]);
    $owner = $stmt->fetchColumn();
    if ($owner !== false) {
        throw new AppException("O SKU {$sku} já está em uso pelo produto \"{$owner}\".", 409);
    }
}

function opProductSave(PDO $pdo, array $user, array $in): string {
    $id = trim((string)($in['id'] ?? ''));
    $f  = validateProductFields($in);

    return runWrite($pdo, function () use ($pdo, $user, $id, $f) {
        if ($id !== '') {
            if (!preg_match(ID_PATTERN, $id)) throw new AppException('Identificador de produto inválido.');
            requireProduct($pdo, $id);
            assertSkuAvailable($pdo, $f['sku'], $id);
            $pdo->prepare('
                UPDATE `products` SET `sku` = ?, `name` = ?, `category` = ?, `unit` = ?, `min_stock` = ?, `max_stock` = ?,
                       `default_expiry` = ?, `storage` = ?, `flag_perecivel` = ?, `flag_epi` = ?, `flag_controlado` = ?, `observations` = ?
                 WHERE `id` = ?
            ')->execute([
                $f['sku'], $f['name'], $f['category'], $f['unit'], $f['min_stock'], $f['max_stock'],
                $f['default_expiry'], $f['storage'], $f['flag_perecivel'], $f['flag_epi'], $f['flag_controlado'], $f['observations'], $id,
            ]);
            logHistory($pdo, $user, 'EDICAO', $f['name'], '-', '-', "SKU {$f['sku']} • {$f['category']}");
            return "Produto \"{$f['name']}\" atualizado!";
        }

        assertSkuAvailable($pdo, $f['sku']);
        insertProduct($pdo, newId('prod'), $f);
        logHistory($pdo, $user, 'CADASTRO', $f['name'], '-', '-', "SKU {$f['sku']} • {$f['category']}");
        return "\"{$f['name']}\" cadastrado com sucesso!";
    });
}

function insertProduct(PDO $pdo, string $id, array $f, string $photo = ''): void {
    $pdo->prepare('
        INSERT INTO `products` (`id`, `sku`, `name`, `category`, `unit`, `min_stock`, `max_stock`, `default_expiry`, `storage`,
                                `flag_perecivel`, `flag_epi`, `flag_controlado`, `photo`, `observations`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $id, $f['sku'], $f['name'], $f['category'], $f['unit'], $f['min_stock'], $f['max_stock'], $f['default_expiry'], $f['storage'],
        $f['flag_perecivel'], $f['flag_epi'], $f['flag_controlado'], $photo, $f['observations'],
    ]);
}

function opProductDelete(PDO $pdo, array $user, array $in): string {
    $id = inputId($in, 'id', 'Produto');
    return runWrite($pdo, function () use ($pdo, $user, $id) {
        $prod = requireProduct($pdo, $id);
        $q1 = $pdo->prepare('SELECT COALESCE(SUM(`qty`), 0) FROM `slots` WHERE `product_id` = ?');
        $q1->execute([$id]);
        $q2 = $pdo->prepare('SELECT COALESCE(SUM(`qty`), 0) FROM `unallocated_lots` WHERE `product_id` = ?');
        $q2->execute([$id]);
        $total = (int)$q1->fetchColumn() + (int)$q2->fetchColumn();

        $pdo->prepare('DELETE FROM `slots` WHERE `product_id` = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM `unallocated_lots` WHERE `product_id` = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM `products` WHERE `id` = ?')->execute([$id]);

        logHistory($pdo, $user, 'EXCLUSAO', productLabel($prod), '-', "-{$total} {$prod['unit']}", "SKU {$prod['sku']} removido do catálogo e do estoque");
        return 'Produto excluído.';
    });
}

// -----------------------------------------------------------------------------
// Movimentações de estoque
// -----------------------------------------------------------------------------

/** Entrada / endereçamento de um lote em uma posição do mapa. */
function opAllocate(PDO $pdo, array $user, array $in): string {
    $productId  = inputId($in, 'productId', 'Produto');
    $slotCode   = inputSlotCode($in, 'slotCode', 'Posição');
    $qty        = inputInt($in, 'qty', 'Quantidade', 1, MAX_QTY);
    $entryDate  = inputDate($in, 'entryDate', 'Data de entrada') ?: date('Y-m-d');
    $expiryDate = inputDate($in, 'expiryDate', 'Validade');
    $lot        = inputString($in, 'lot', 'Lote', 64);
    $displace   = !empty($in['displace']);

    return runWrite($pdo, function () use ($pdo, $user, $productId, $slotCode, $qty, $entryDate, $expiryDate, $lot, $displace) {
        $prod = requireProduct($pdo, $productId);
        requireValidSlotCode($pdo, $slotCode);

        // Primeiro consome lotes do Estoque Geral deste produto (vence primeiro, sai primeiro)
        $remaining = $qty;
        $fromGeneral = 0;
        $lots = $pdo->prepare("SELECT * FROM `unallocated_lots` WHERE `product_id` = ? AND `qty` > 0
                                ORDER BY (`expiry_date` IS NULL OR `expiry_date` = ''), `expiry_date`, `entry_date`");
        $lots->execute([$productId]);
        foreach ($lots->fetchAll() as $l) {
            if ($remaining <= 0) break;
            $take = min((int)$l['qty'], $remaining);
            $remaining -= $take;
            $fromGeneral += $take;
            if ($take >= (int)$l['qty']) {
                $pdo->prepare('DELETE FROM `unallocated_lots` WHERE `id` = ?')->execute([$l['id']]);
            } else {
                $pdo->prepare('UPDATE `unallocated_lots` SET `qty` = `qty` - ? WHERE `id` = ?')->execute([$take, $l['id']]);
            }
        }

        $existing = findSlot($pdo, $slotCode);
        if ($existing && $existing['product_id'] === $productId) {
            $newQty = (int)$existing['qty'] + $qty;
            if ($newQty > MAX_QTY) throw new AppException('A quantidade total da posição ultrapassaria o limite permitido.');
            $pdo->prepare('UPDATE `slots` SET `qty` = ?, `entry_date` = ?, `expiry_date` = ?, `lot` = IF(? = \'\', `lot`, ?) WHERE `slot_code` = ?')
                ->execute([$newQty, $entryDate, $expiryDate, $lot, $lot, $slotCode]);
        } else {
            if ($existing) {
                if (!$displace) {
                    throw new AppException("A posição {$slotCode} está ocupada por outro produto. Confirme para movê-lo ao Estoque Geral.", 409);
                }
                moveSlotToGeneral($pdo, $user, $existing, 'Movido para Estoque Geral ao realocar posição');
            }
            $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$slotCode]);
            $pdo->prepare('INSERT INTO `slots` (`slot_code`, `product_id`, `qty`, `entry_date`, `expiry_date`, `lot`, `observations`) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$slotCode, $productId, $qty, $entryDate, $expiryDate, $lot, (string)($prod['observations'] ?? '')]);
        }

        $note = 'Validade: ' . formatDateBR($expiryDate) . ($lot !== '' ? " • Lote: {$lot}" : '');
        if ($fromGeneral > 0) {
            $note .= " • {$fromGeneral} {$prod['unit']} vindos do Estoque Geral";
        }
        logHistory($pdo, $user, 'ENTRADA', productLabel($prod), $slotCode, "+{$qty} {$prod['unit']}", $note);
        return "{$qty} {$prod['unit']} alocado(s) em {$slotCode}!";
    });
}

/** Baixa (saída) de uma posição. Nunca permite retirar mais que o saldo. */
function opWithdraw(PDO $pdo, array $user, array $in): string {
    $slotCode = inputSlotCode($in, 'slotCode', 'Posição');
    $qty      = inputInt($in, 'qty', 'Quantidade', 1, MAX_QTY);
    $note     = inputString($in, 'note', 'Motivo', 255);

    return runWrite($pdo, function () use ($pdo, $user, $slotCode, $qty, $note) {
        $slot = requireOccupiedSlot($pdo, $slotCode);
        $prod = requireProduct($pdo, $slot['product_id']);
        $balance = (int)$slot['qty'];
        if ($qty > $balance) {
            throw new AppException("Quantidade solicitada ({$qty}) maior que o saldo da posição {$slotCode} ({$balance} {$prod['unit']}).", 422);
        }
        if ($qty === $balance) {
            $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$slotCode]);
        } else {
            $pdo->prepare('UPDATE `slots` SET `qty` = `qty` - ? WHERE `slot_code` = ?')->execute([$qty, $slotCode]);
        }
        logHistory($pdo, $user, 'SAIDA', productLabel($prod), $slotCode, "-{$qty} {$prod['unit']}", $note ?: 'Baixa manual');
        return "Baixa de {$qty} {$prod['unit']} registrada!";
    });
}

/** Transferência entre posições. A quantidade nunca ultrapassa o saldo de origem. */
function opTransfer(PDO $pdo, array $user, array $in): string {
    $from     = inputSlotCode($in, 'fromCode', 'Origem');
    $to       = inputSlotCode($in, 'toCode', 'Destino');
    $qty      = inputInt($in, 'qty', 'Quantidade', 1, MAX_QTY);
    $displace = !empty($in['displace']);
    if ($from === $to) {
        throw new AppException('A posição de destino deve ser diferente da origem.');
    }

    return runWrite($pdo, function () use ($pdo, $user, $from, $to, $qty, $displace) {
        requireValidSlotCode($pdo, $to);
        $src  = requireOccupiedSlot($pdo, $from);
        $prod = requireProduct($pdo, $src['product_id']);
        $balance = (int)$src['qty'];
        if ($qty > $balance) {
            throw new AppException("Quantidade ({$qty}) maior que o saldo disponível em {$from} ({$balance} {$prod['unit']}).", 422);
        }

        $dest = findSlot($pdo, $to);
        if ($dest && $dest['product_id'] === $src['product_id']) {
            if ((int)$dest['qty'] + $qty > MAX_QTY) throw new AppException('A quantidade total do destino ultrapassaria o limite permitido.');
            $pdo->prepare('UPDATE `slots` SET `qty` = `qty` + ? WHERE `slot_code` = ?')->execute([$qty, $to]);
        } else {
            if ($dest) {
                if (!$displace) {
                    throw new AppException("A posição {$to} está ocupada por outro produto. Confirme para movê-lo ao Estoque Geral.", 409);
                }
                moveSlotToGeneral($pdo, $user, $dest, 'Movido para Estoque Geral ao receber transferência');
            }
            $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$to]);
            $pdo->prepare('INSERT INTO `slots` (`slot_code`, `product_id`, `qty`, `entry_date`, `expiry_date`, `lot`, `observations`) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$to, $src['product_id'], $qty, $src['entry_date'], $src['expiry_date'], $src['lot'], (string)($src['observations'] ?? '')]);
        }

        if ($qty === $balance) {
            $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$from]);
        } else {
            $pdo->prepare('UPDATE `slots` SET `qty` = `qty` - ? WHERE `slot_code` = ?')->execute([$qty, $from]);
        }

        logHistory($pdo, $user, 'TRANSFERENCIA', productLabel($prod), "{$from} → {$to}", "{$qty} {$prod['unit']}", 'Remanejamento interno');
        return "Transferido para {$to}!";
    });
}

function opSlotClear(PDO $pdo, array $user, array $in): string {
    $slotCode = inputSlotCode($in, 'slotCode', 'Posição');
    return runWrite($pdo, function () use ($pdo, $user, $slotCode) {
        $slot = requireOccupiedSlot($pdo, $slotCode);
        $prod = findProduct($pdo, $slot['product_id']);
        $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$slotCode]);
        logHistory($pdo, $user, 'SAIDA', $prod ? productLabel($prod) : 'Produto removido', $slotCode, "-{$slot['qty']} " . ($prod['unit'] ?? 'un'), 'Posição esvaziada');
        return "Posição {$slotCode} liberada.";
    });
}

function opExpiryUpdate(PDO $pdo, array $user, array $in): string {
    $type = (string)($in['targetType'] ?? '');
    $date = inputDate($in, 'date', 'Nova validade', true);

    if ($type === 'slot') {
        $code = inputSlotCode($in, 'id', 'Posição');
        return runWrite($pdo, function () use ($pdo, $user, $code, $date) {
            $slot = requireOccupiedSlot($pdo, $code);
            $prod = requireProduct($pdo, $slot['product_id']);
            $pdo->prepare('UPDATE `slots` SET `expiry_date` = ? WHERE `slot_code` = ?')->execute([$date, $code]);
            logHistory($pdo, $user, 'VALIDADE', productLabel($prod), $code, "{$slot['qty']} {$prod['unit']}", 'Nova validade: ' . formatDateBR($date));
            return 'Data de validade atualizada!';
        });
    }
    if ($type === 'product') {
        $id = inputId($in, 'id', 'Produto');
        return runWrite($pdo, function () use ($pdo, $user, $id, $date) {
            $prod = requireProduct($pdo, $id);
            $pdo->prepare('UPDATE `products` SET `default_expiry` = ? WHERE `id` = ?')->execute([$date, $id]);
            logHistory($pdo, $user, 'VALIDADE', productLabel($prod), '-', '-', 'Validade padrão: ' . formatDateBR($date));
            return 'Data de validade atualizada!';
        });
    }
    throw new AppException('Tipo de alteração de validade inválido.');
}

/** Ajuste rápido ±1 do Resumo Geral. A saída segue FEFO (vence primeiro, sai primeiro). */
function opQuickAdjust(PDO $pdo, array $user, array $in): string {
    $productId = inputId($in, 'productId', 'Produto');
    $delta     = inputInt($in, 'delta', 'Ajuste', -1, 1);
    if ($delta === 0) throw new AppException('Ajuste inválido.');

    return runWrite($pdo, function () use ($pdo, $user, $productId, $delta) {
        $prod = requireProduct($pdo, $productId);

        if ($delta > 0) {
            $stmt = $pdo->prepare('SELECT * FROM `slots` WHERE `product_id` = ? AND `qty` > 0 ORDER BY `slot_code` LIMIT 1');
            $stmt->execute([$productId]);
            $slot = $stmt->fetch();
            if ($slot) {
                if ((int)$slot['qty'] >= MAX_QTY) throw new AppException('Limite de quantidade atingido.');
                $pdo->prepare('UPDATE `slots` SET `qty` = `qty` + 1 WHERE `slot_code` = ?')->execute([$slot['slot_code']]);
                $where = $slot['slot_code'];
            } else {
                $stmt = $pdo->prepare('SELECT * FROM `unallocated_lots` WHERE `product_id` = ? AND `qty` > 0 ORDER BY `entry_date` LIMIT 1');
                $stmt->execute([$productId]);
                $lot = $stmt->fetch();
                if ($lot) {
                    $pdo->prepare('UPDATE `unallocated_lots` SET `qty` = `qty` + 1 WHERE `id` = ?')->execute([$lot['id']]);
                } else {
                    $pdo->prepare('INSERT INTO `unallocated_lots` (`id`, `product_id`, `qty`, `entry_date`, `expiry_date`, `observations`) VALUES (?, ?, 1, ?, ?, ?)')
                        ->execute([newId('lot'), $productId, date('Y-m-d'), (string)($prod['default_expiry'] ?? ''), (string)($prod['observations'] ?? '')]);
                }
                $where = 'Geral';
            }
            logHistory($pdo, $user, 'ENTRADA', productLabel($prod), $where, "+1 {$prod['unit']}", 'Ajuste rápido (+) no Resumo Geral');
            return "+1 {$prod['unit']} em \"{$prod['name']}\"";
        }

        // Saída: escolhe o lote/posição com vencimento mais próximo (sem validade = por último)
        $candidates = [];
        $s = $pdo->prepare('SELECT `slot_code` AS `ref`, `qty`, `expiry_date` FROM `slots` WHERE `product_id` = ? AND `qty` > 0');
        $s->execute([$productId]);
        foreach ($s->fetchAll() as $r) $candidates[] = ['type' => 'slot'] + $r;
        $l = $pdo->prepare('SELECT `id` AS `ref`, `qty`, `expiry_date` FROM `unallocated_lots` WHERE `product_id` = ? AND `qty` > 0');
        $l->execute([$productId]);
        foreach ($l->fetchAll() as $r) $candidates[] = ['type' => 'lot'] + $r;

        if (!$candidates) {
            throw new AppException('Produto já está com saldo zerado.', 422);
        }
        usort($candidates, function ($a, $b) {
            $ea = isValidIsoDate((string)$a['expiry_date']) ? $a['expiry_date'] : '9999-12-31';
            $eb = isValidIsoDate((string)$b['expiry_date']) ? $b['expiry_date'] : '9999-12-31';
            return strcmp($ea, $eb);
        });
        $t = $candidates[0];

        if ($t['type'] === 'slot') {
            if ((int)$t['qty'] <= 1) $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$t['ref']]);
            else $pdo->prepare('UPDATE `slots` SET `qty` = `qty` - 1 WHERE `slot_code` = ?')->execute([$t['ref']]);
            $where = $t['ref'];
        } else {
            if ((int)$t['qty'] <= 1) $pdo->prepare('DELETE FROM `unallocated_lots` WHERE `id` = ?')->execute([$t['ref']]);
            else $pdo->prepare('UPDATE `unallocated_lots` SET `qty` = `qty` - 1 WHERE `id` = ?')->execute([$t['ref']]);
            $where = 'Geral';
        }
        logHistory($pdo, $user, 'SAIDA', productLabel($prod), $where, "-1 {$prod['unit']}", 'Ajuste rápido (−) no Resumo Geral');
        return "-1 {$prod['unit']} em \"{$prod['name']}\"";
    });
}

// -----------------------------------------------------------------------------
// Posições do mapa (administrador)
// -----------------------------------------------------------------------------

function opCustomSlotAdd(PDO $pdo, array $user, array $in): string {
    $code  = strtoupper(inputString($in, 'code', 'Código', 6, true));
    $label = inputString($in, 'label', 'Descrição', 128, true);
    if (!preg_match(CUSTOM_SLOT_REGEX, $code)) {
        throw new AppException('Código da posição inválido: use até 6 letras, números, hífen ou sublinhado.');
    }
    return runWrite($pdo, function () use ($pdo, $user, $code, $label) {
        if (in_array($code, allSlotCodes($pdo), true)) {
            throw new AppException('Já existe uma posição com esse código.', 409);
        }
        $pdo->prepare('INSERT INTO `custom_slots` (`code`, `label`) VALUES (?, ?)')->execute([$code, $label]);
        logHistory($pdo, $user, 'SISTEMA', 'Mapa 2D', $code, '-', "Posição extra criada: {$label}");
        return "Posição {$code} adicionada ao mapa!";
    });
}

function opCustomSlotRemove(PDO $pdo, array $user, array $in): string {
    $code = inputSlotCode($in, 'code', 'Posição');
    return runWrite($pdo, function () use ($pdo, $user, $code) {
        $stmt = $pdo->prepare('SELECT 1 FROM `custom_slots` WHERE `code` = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            throw new AppException("A posição extra {$code} não existe.", 404);
        }
        $slot = findSlot($pdo, $code);
        if ($slot) {
            // O saldo não é perdido: vai para o Estoque Geral
            moveSlotToGeneral($pdo, $user, $slot, 'Posição extra removida do mapa');
        }
        $pdo->prepare('DELETE FROM `slots` WHERE `slot_code` = ?')->execute([$code]);
        $pdo->prepare('DELETE FROM `custom_slots` WHERE `code` = ?')->execute([$code]);
        logHistory($pdo, $user, 'SISTEMA', 'Mapa 2D', $code, '-', 'Posição extra removida');
        return $slot ? "Posição {$code} removida. O saldo foi movido para o Estoque Geral." : "Posição {$code} removida.";
    });
}

function opSlotsResetAll(PDO $pdo, array $user): string {
    return runWrite($pdo, function () use ($pdo, $user) {
        $row = $pdo->query('SELECT COUNT(*) AS `n`, COALESCE(SUM(`qty`), 0) AS `total` FROM `slots` WHERE `qty` > 0')->fetch();
        $pdo->exec('DELETE FROM `slots`');
        logHistory($pdo, $user, 'SAIDA', 'Todas as posições', 'MAPA', "-{$row['total']} itens", "Limpeza geral do mapa 2D ({$row['n']} posições)");
        return 'Todas as posições do mapa foram liberadas.';
    });
}

// -----------------------------------------------------------------------------
// Importação de planilha e backup
// -----------------------------------------------------------------------------

function nextFreeImportSku(PDO $pdo, array &$usedSkus): string {
    $n = count($usedSkus) + 1;
    do {
        $sku = 'IMP-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $n++;
    } while (isset($usedSkus[$sku]));
    return $sku;
}

function opImportItems(PDO $pdo, array $user, array $in): string {
    $items = $in['items'] ?? null;
    if (!is_array($items) || count($items) === 0) {
        throw new AppException('Nenhum item para importar.');
    }
    if (count($items) > MAX_IMPORT_ITEMS) {
        throw new AppException('A planilha tem itens demais (máximo de ' . MAX_IMPORT_ITEMS . ' por importação).');
    }
    $mode = ($in['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
    if ($mode === 'replace' && !isAdmin($user)) {
        throw new AppException('Somente administradores podem substituir o estoque pela planilha.', 403);
    }
    $autoAllocate = !empty($in['autoAllocate']);

    // Valida tudo antes de gravar qualquer coisa
    $clean = [];
    foreach (array_values($items) as $i => $item) {
        $row = $i + 1;
        if (!is_array($item)) throw new AppException("Linha {$row}: formato inválido.");
        try {
            $clean[] = [
                'name'         => inputString($item, 'name', 'Produto', 255, true),
                'sku'          => normalizeSku(inputString($item, 'sku', 'SKU', 32)),
                'category'     => inputString($item, 'category', 'Categoria', 64) ?: 'Geral',
                'unit'         => inputString($item, 'unit', 'Unidade', 16) ?: 'un',
                'qty'          => inputInt(['v' => $item['qty'] ?? 0], 'v', 'Quantidade', 0, MAX_QTY),
                'expiryDate'   => inputDate($item, 'expiryDate', 'Validade'),
                'slotCode'     => strtoupper(inputString($item, 'slotCode', 'Posição', 16)),
                'observations' => inputString($item, 'observations', 'Observações', 2000),
            ];
        } catch (AppException $e) {
            throw new AppException("Linha {$row}: " . $e->getMessage());
        }
    }

    return runWrite($pdo, function () use ($pdo, $user, $clean, $mode, $autoAllocate) {
        if ($mode === 'replace') {
            $pdo->exec('DELETE FROM `slots`');
            $pdo->exec('DELETE FROM `unallocated_lots`');
            $pdo->exec('DELETE FROM `products`');
        }

        $products = $pdo->query('SELECT `id`, `sku`, `name`, `category` FROM `products`')->fetchAll();
        $bySku = [];
        $byNameCat = [];
        foreach ($products as $p) {
            $bySku[normalizeSku($p['sku'])] = $p['id'];
            $byNameCat[mb_strtolower($p['name']) . '|' . mb_strtolower($p['category'])] = $p['id'];
        }
        $occupied = array_fill_keys($pdo->query('SELECT `slot_code` FROM `slots` WHERE `qty` > 0')->fetchAll(PDO::FETCH_COLUMN), true);
        $slotOrder = allSlotCodes($pdo);
        $nextFree = 0;
        $created = 0;
        $renamedSkus = 0;
        $units = 0;

        foreach ($clean as $item) {
            $key = mb_strtolower($item['name']) . '|' . mb_strtolower($item['category']);
            $productId = $byNameCat[$key] ?? null;

            if (!$productId) {
                $sku = $item['sku'];
                if ($sku === '' || !preg_match(SKU_PATTERN, $sku) || isset($bySku[$sku])) {
                    if ($sku !== '') $renamedSkus++;
                    $sku = nextFreeImportSku($pdo, $bySku);
                }
                $productId = newId('prod');
                insertProduct($pdo, $productId, [
                    'sku' => $sku, 'name' => $item['name'], 'category' => $item['category'], 'unit' => $item['unit'],
                    'min_stock' => 2, 'max_stock' => null, 'default_expiry' => $item['expiryDate'], 'storage' => '',
                    'flag_perecivel' => 0, 'flag_epi' => 0, 'flag_controlado' => 0, 'observations' => $item['observations'],
                ]);
                $bySku[$sku] = $productId;
                $byNameCat[$key] = $productId;
                $created++;
            }

            if ($item['qty'] <= 0) continue;
            $units += $item['qty'];

            $target = '';
            if ($item['slotCode'] !== '' && in_array($item['slotCode'], $slotOrder, true) && empty($occupied[$item['slotCode']])) {
                $target = $item['slotCode'];
            } elseif ($autoAllocate) {
                while ($nextFree < count($slotOrder) && !empty($occupied[$slotOrder[$nextFree]])) $nextFree++;
                if ($nextFree < count($slotOrder)) $target = $slotOrder[$nextFree++];
            }

            if ($target !== '') {
                $pdo->prepare('INSERT INTO `slots` (`slot_code`, `product_id`, `qty`, `entry_date`, `expiry_date`, `lot`, `observations`) VALUES (?, ?, ?, ?, ?, \'\', ?)')
                    ->execute([$target, $productId, $item['qty'], date('Y-m-d'), $item['expiryDate'], $item['observations']]);
                $occupied[$target] = true;
            } else {
                $pdo->prepare('INSERT INTO `unallocated_lots` (`id`, `product_id`, `qty`, `entry_date`, `expiry_date`, `observations`) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([newId('lot'), $productId, $item['qty'], date('Y-m-d'), $item['expiryDate'], $item['observations']]);
            }
        }

        $total = count($clean);
        logHistory($pdo, $user, 'ENTRADA', "Importação Planilha ({$total} itens)", 'GERAL', "{$units} unidades",
            'Modo: ' . ($mode === 'replace' ? 'Substituição' : 'Mesclagem') . " • {$created} produtos novos");

        $msg = "{$total} itens importados com sucesso!";
        if ($renamedSkus > 0) $msg .= " {$renamedSkus} SKU(s) repetido(s) ou inválido(s) receberam código automático.";
        return $msg;
    });
}

/** Restaura um backup JSON completo. Tudo é validado antes de apagar os dados atuais. */
function opBackupRestore(PDO $pdo, array $user, array $in): string {
    $b = $in['backup'] ?? null;
    if (!is_array($b) || !isset($b['products']) || !is_array($b['products'])) {
        throw new AppException('Arquivo de backup inválido: lista de produtos ausente.');
    }

    $products = [];
    $skus = [];
    foreach (array_values($b['products']) as $i => $p) {
        $n = $i + 1;
        if (!is_array($p)) throw new AppException("Backup: produto {$n} inválido.");
        try {
            $id = inputId($p, 'id', 'ID');
            $f  = validateProductFields($p);
        } catch (AppException $e) {
            throw new AppException("Backup, produto {$n}: " . $e->getMessage());
        }
        if (isset($products[$id])) throw new AppException("Backup: o ID de produto {$id} aparece mais de uma vez.");
        if (isset($skus[$f['sku']])) throw new AppException("Backup: o SKU {$f['sku']} aparece em mais de um produto.");
        $skus[$f['sku']] = true;
        $products[$id] = ['f' => $f, 'photo' => cut((string)($p['photo'] ?? ''), 2000)];
    }

    $customSlots = [];
    foreach ((array)($b['customSlots'] ?? []) as $i => $cs) {
        try {
            $code  = strtoupper(inputString((array)$cs, 'code', 'Código', 16, true));
            $label = inputString((array)$cs, 'label', 'Descrição', 128) ?: $code;
        } catch (AppException $e) {
            throw new AppException('Backup, posição extra ' . ($i + 1) . ': ' . $e->getMessage());
        }
        if (!preg_match(STORED_SLOT_REGEX, $code) || in_array($code, BASE_SLOT_CODES, true) || isset($customSlots[$code])) {
            throw new AppException("Backup: posição extra {$code} inválida ou repetida.");
        }
        $customSlots[$code] = $label;
    }
    $validCodes = array_merge(BASE_SLOT_CODES, array_keys($customSlots));

    $slots = [];
    foreach ((array)($b['slots'] ?? []) as $code => $s) {
        if (!$s) continue;
        $code = strtoupper((string)$code);
        try {
            if (!in_array($code, $validCodes, true)) throw new AppException('posição inexistente no mapa');
            $slot = [
                'productId'    => inputId((array)$s, 'productId', 'Produto'),
                'qty'          => inputInt((array)$s, 'qty', 'Quantidade', 1, MAX_QTY),
                'entryDate'    => inputDate((array)$s, 'entryDate', 'Entrada'),
                'expiryDate'   => inputDate((array)$s, 'expiryDate', 'Validade'),
                'lot'          => inputString((array)$s, 'lot', 'Lote', 64),
                'observations' => inputString((array)$s, 'observations', 'Observações', 2000),
            ];
        } catch (AppException $e) {
            throw new AppException("Backup, posição {$code}: " . $e->getMessage());
        }
        if (!isset($products[$slot['productId']])) throw new AppException("Backup, posição {$code}: produto inexistente.");
        $slots[$code] = $slot;
    }

    $lots = [];
    foreach ((array)($b['unallocatedLots'] ?? []) as $i => $l) {
        try {
            $lot = [
                'id'           => !empty($l['id']) ? inputId((array)$l, 'id', 'ID') : newId('lot'),
                'productId'    => inputId((array)$l, 'productId', 'Produto'),
                'qty'          => inputInt((array)$l, 'qty', 'Quantidade', 1, MAX_QTY),
                'entryDate'    => inputDate((array)$l, 'entryDate', 'Entrada'),
                'expiryDate'   => inputDate((array)$l, 'expiryDate', 'Validade'),
                'observations' => inputString((array)$l, 'observations', 'Observações', 2000),
            ];
        } catch (AppException $e) {
            throw new AppException('Backup, lote ' . ($i + 1) . ': ' . $e->getMessage());
        }
        if (!isset($products[$lot['productId']])) throw new AppException('Backup, lote ' . ($i + 1) . ': produto inexistente.');
        $lots[$lot['id']] = $lot;
    }

    $history = [];
    foreach (array_slice((array)($b['history'] ?? []), 0, 250) as $h) {
        if (!is_array($h) || empty($h['id']) || !preg_match(ID_PATTERN, (string)$h['id'])) continue;
        $author = (string)($h['user'] ?? '');
        $history[] = [
            (string)$h['id'], cut((string)($h['timestamp'] ?? ''), 32), cut((string)($h['type'] ?? 'GERAL'), 32),
            cut((string)($h['productName'] ?? '-'), 255), cut((string)($h['slotCode'] ?? '-'), 64), cut((string)($h['qtyText'] ?? '-'), 64),
            cut((string)($h['note'] ?? '-'), 2000), preg_match('/^[a-z0-9._-]{3,32}$/', $author) ? $author : null,
        ];
    }

    return runWrite($pdo, function () use ($pdo, $user, $products, $customSlots, $slots, $lots, $history) {
        $pdo->exec('DELETE FROM `slots`');
        $pdo->exec('DELETE FROM `unallocated_lots`');
        $pdo->exec('DELETE FROM `custom_slots`');
        $pdo->exec('DELETE FROM `products`');

        foreach ($products as $id => $p) insertProduct($pdo, $id, $p['f'], $p['photo']);
        $stmt = $pdo->prepare('INSERT INTO `custom_slots` (`code`, `label`) VALUES (?, ?)');
        foreach ($customSlots as $code => $label) $stmt->execute([$code, $label]);
        $stmt = $pdo->prepare('INSERT INTO `slots` (`slot_code`, `product_id`, `qty`, `entry_date`, `expiry_date`, `lot`, `observations`) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($slots as $code => $s) $stmt->execute([$code, $s['productId'], $s['qty'], $s['entryDate'], $s['expiryDate'], $s['lot'], $s['observations']]);
        $stmt = $pdo->prepare('INSERT INTO `unallocated_lots` (`id`, `product_id`, `qty`, `entry_date`, `expiry_date`, `observations`) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lots as $l) $stmt->execute([$l['id'], $l['productId'], $l['qty'], $l['entryDate'], $l['expiryDate'], $l['observations']]);
        // Histórico do backup: registros já existentes são mantidos; autor original preservado (sem vínculo de ID)
        $stmt = $pdo->prepare('INSERT INTO `history` (`id`, `timestamp`, `type`, `product_name`, `slot_code`, `qty_text`, `note`, `username`) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `id` = `id`');
        foreach ($history as $h) $stmt->execute($h);

        logHistory($pdo, $user, 'SISTEMA', 'Backup JSON', '-', count($products) . ' produtos', 'Backup restaurado pelo administrador');
        return 'Backup restaurado com sucesso!';
    });
}

// -----------------------------------------------------------------------------
// Limpezas (administrador)
// -----------------------------------------------------------------------------

function opClearHistory(PDO $pdo, array $user): string {
    return runWrite($pdo, function () use ($pdo, $user) {
        $pdo->exec('DELETE FROM `history`');
        logHistory($pdo, $user, 'SISTEMA', 'Histórico de movimentações', '-', '-', 'Histórico limpo pelo administrador');
        return 'Histórico limpo com sucesso.';
    });
}

function opResetAll(PDO $pdo, array $user): string {
    return runWrite($pdo, function () use ($pdo, $user) {
        $pdo->exec('DELETE FROM `slots`');
        $pdo->exec('DELETE FROM `unallocated_lots`');
        $pdo->exec('DELETE FROM `custom_slots`');
        $pdo->exec('DELETE FROM `history`');
        $pdo->exec('DELETE FROM `products`');
        logHistory($pdo, $user, 'SISTEMA', 'Todos os dados do estoque', '-', '-', 'Banco de dados zerado pelo administrador');
        return 'Todos os dados do programa e do banco foram limpos!';
    });
}
