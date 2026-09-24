<?php
require __DIR__ . '/../db/conexao.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $pdo = conectarBanco();
    $rows = $pdo->query("
        SELECT ci.loja_id, ci.fornecedor_id, ci.nota_referencia, ci.data_compra,
               COUNT(*) AS total_itens, l.nome AS loja_nome, f.nome AS fornecedor_nome
        FROM compras_itens ci
        JOIN lojas l ON l.id = ci.loja_id
        LEFT JOIN fornecedores f ON f.id = ci.fornecedor_id
        WHERE ci.vinculado = 0
        GROUP BY ci.loja_id, ci.fornecedor_id, ci.nota_referencia, ci.data_compra
        ORDER BY ci.data_compra ASC
    ")->fetchAll();
    echo json_encode(['ok' => true, 'pendentes' => $rows], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
