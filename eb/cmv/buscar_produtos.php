<?php
require __DIR__ . '/../db/conexao.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$q = trim($_GET['q'] ?? '');
try {
    $pdo = conectarBanco();
    if ($q === '') {
        $rows = $pdo->query("SELECT id, nome_padrao, unidade_padrao FROM produtos_cmv WHERE ativo=1 ORDER BY nome_padrao LIMIT 50")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, nome_padrao, unidade_padrao FROM produtos_cmv WHERE ativo=1 AND nome_padrao LIKE ? ORDER BY nome_padrao LIMIT 50");
        $stmt->execute(['%' . $q . '%']);
        $rows = $stmt->fetchAll();
    }
    echo json_encode(['ok' => true, 'produtos' => $rows], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
