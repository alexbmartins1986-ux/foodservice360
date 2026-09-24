<?php
require __DIR__ . '/../db/conexao.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $pdo = conectarBanco();
    $rows = $pdo->query("
        SELECT c.id, c.setor, c.responsavel, c.enviada_em, l.nome AS loja_nome,
               (SELECT COUNT(*) FROM contagens_itens WHERE contagem_id = c.id) AS total_itens
        FROM contagens c
        JOIN lojas l ON l.id = c.loja_id
        WHERE c.vinculada = 0
        ORDER BY c.enviada_em ASC
    ")->fetchAll();
    echo json_encode(['ok' => true, 'pendentes' => $rows], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
