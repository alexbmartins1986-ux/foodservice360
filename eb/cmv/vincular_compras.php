<?php
require __DIR__ . '/../db/conexao.php';
require __DIR__ . '/lib/texto.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$dados = json_decode(file_get_contents('php://input'), true);
$vinculos = $dados['vinculos'] ?? [];
if (!is_array($vinculos) || empty($vinculos)) {
    echo json_encode(['ok' => false, 'erro' => 'Nenhum vínculo recebido.']); exit;
}

try {
    $pdo = conectarBanco();
    $pdo->beginTransaction();

    $produtosParaRecalcular = [];

    foreach ($vinculos as $v) {
        $itemId = (int)($v['item_id'] ?? 0);
        if (!$itemId) throw new Exception('Item sem id.');

        $stmtItem = $pdo->prepare("SELECT * FROM compras_itens WHERE id = ? AND vinculado = 0");
        $stmtItem->execute([$itemId]);
        $item = $stmtItem->fetch();
        if (!$item) throw new Exception("Item $itemId não encontrado ou já vinculado.");

        // pega o cliente_id pela loja do item, pra criar produto novo no cliente certo
        $stmtCli = $pdo->prepare("SELECT cliente_id FROM lojas WHERE id = ?");
        $stmtCli->execute([$item['loja_id']]);
        $clienteId = $stmtCli->fetchColumn();

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

        $pdo->prepare("UPDATE compras_itens SET produto_cmv_id = ?, vinculado = 1, vinculado_em = NOW() WHERE id = ?")
            ->execute([$produtoId, $itemId]);

        // aprende o apelido, JA COM O FORNECEDOR, se ainda nao conhecido pra esse fornecedor
        $fornecedorId = $item['fornecedor_id'];
        $stmtApelidos = $pdo->prepare("SELECT apelido FROM produtos_apelidos WHERE produto_cmv_id = ? AND (fornecedor_id <=> ?)");
        $stmtApelidos->execute([$produtoId, $fornecedorId]);
        $jaConhecidos = array_map('normalizarTexto', $stmtApelidos->fetchAll(PDO::FETCH_COLUMN));

        if (!in_array(normalizarTexto($item['produto_texto_original']), $jaConhecidos, true)) {
            $pdo->prepare("INSERT INTO produtos_apelidos (produto_cmv_id, apelido, fornecedor_id) VALUES (?, ?, ?)")
                ->execute([$produtoId, $item['produto_texto_original'], $fornecedorId]);
        }

        $produtosParaRecalcular[$produtoId] = true;
    }

    // recalcula o custo_atual de cada produto tocado: media das ultimas 4 compras
    foreach (array_keys($produtosParaRecalcular) as $produtoId) {
        $stmtMedia = $pdo->prepare("
            SELECT AVG(valor_unitario) FROM (
                SELECT valor_unitario FROM compras_itens
                WHERE produto_cmv_id = ? ORDER BY data_compra DESC LIMIT 4
            ) x");
        $stmtMedia->execute([$produtoId]);
        $media = $stmtMedia->fetchColumn();
        if ($media !== null) {
            $pdo->prepare("UPDATE produtos_cmv SET custo_atual = ? WHERE id = ?")->execute([$media, $produtoId]);
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
