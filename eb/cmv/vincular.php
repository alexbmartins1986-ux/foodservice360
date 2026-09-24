<?php
require __DIR__ . '/../db/conexao.php';
require __DIR__ . '/lib/texto.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$dados = json_decode(file_get_contents('php://input'), true);
$contagemId = (int)($dados['contagem_id'] ?? 0);
$vinculos = $dados['vinculos'] ?? [];

if (!$contagemId || !is_array($vinculos) || empty($vinculos)) {
    echo json_encode(['ok' => false, 'erro' => 'Dados incompletos.']);
    exit;
}

try {
    $pdo = conectarBanco();
    $pdo->beginTransaction();

    // pega o cliente_id da loja dessa contagem, pra criar produto novo no cliente certo
    $stmt = $pdo->prepare("SELECT l.cliente_id FROM contagens c JOIN lojas l ON l.id = c.loja_id WHERE c.id = ?");
    $stmt->execute([$contagemId]);
    $clienteId = $stmt->fetchColumn();
    if (!$clienteId) throw new Exception('Contagem ou loja não encontrada.');

    foreach ($vinculos as $v) {
        $itemId = (int)($v['item_id'] ?? 0);
        if (!$itemId) throw new Exception('Item sem id.');

        // pega o texto original desse item, pra registrar o apelido depois
        $stmtItem = $pdo->prepare("SELECT produto_texto_original FROM contagens_itens WHERE id = ? AND contagem_id = ?");
        $stmtItem->execute([$itemId, $contagemId]);
        $textoOriginal = $stmtItem->fetchColumn();
        if ($textoOriginal === false) throw new Exception("Item $itemId não pertence a esta contagem.");

        if (($v['tipo'] ?? '') === 'novo') {
            $nome = trim($v['nome'] ?? '');
            $unidade = trim($v['unidade'] ?? '');
            if ($nome === '' || $unidade === '') throw new Exception('Produto novo sem nome ou unidade.');
            $stmtNovo = $pdo->prepare("INSERT INTO produtos_cmv (cliente_id, nome_padrao, unidade_padrao) VALUES (?, ?, ?)");
            $stmtNovo->execute([$clienteId, $nome, $unidade]);
            $produtoId = (int)$pdo->lastInsertId();
        } else {
            $produtoId = (int)($v['produto_cmv_id'] ?? 0);
            if (!$produtoId) throw new Exception("Item $itemId sem produto vinculado.");
        }

        // vincula o item ao produto
        $pdo->prepare("UPDATE contagens_itens SET produto_cmv_id = ? WHERE id = ?")->execute([$produtoId, $itemId]);

        // aprende o apelido, se esse texto ainda não é conhecido pra esse produto
        $stmtApelidos = $pdo->prepare("SELECT apelido FROM produtos_apelidos WHERE produto_cmv_id = ?");
        $stmtApelidos->execute([$produtoId]);
        $jaConhecidos = array_map('normalizarTexto', $stmtApelidos->fetchAll(PDO::FETCH_COLUMN));
        $stmtNomeProduto = $pdo->prepare("SELECT nome_padrao FROM produtos_cmv WHERE id = ?");
        $stmtNomeProduto->execute([$produtoId]);
        $jaConhecidos[] = normalizarTexto($stmtNomeProduto->fetchColumn());

        if (!in_array(normalizarTexto($textoOriginal), $jaConhecidos, true)) {
            $pdo->prepare("INSERT INTO produtos_apelidos (produto_cmv_id, apelido) VALUES (?, ?)")
                ->execute([$produtoId, $textoOriginal]);
        }
    }

    $pdo->prepare("UPDATE contagens SET vinculada = 1, vinculada_em = NOW() WHERE id = ?")->execute([$contagemId]);

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
