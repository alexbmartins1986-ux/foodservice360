<?php
require __DIR__ . '/../db/conexao.php';
require __DIR__ . '/lib/texto.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$lojaId = (int)($_GET['loja_id'] ?? 0);
$fornecedorId = $_GET['fornecedor_id'] ?? '';
$fornecedorId = $fornecedorId === '' ? null : (int)$fornecedorId;
$notaRef = $_GET['nota_referencia'] ?? '';
$data = $_GET['data_compra'] ?? '';

if (!$lojaId || $notaRef === '' || $data === '') {
    echo json_encode(['ok' => false, 'erro' => 'Parâmetros incompletos.']); exit;
}

try {
    $pdo = conectarBanco();

    $stmtLoja = $pdo->prepare("SELECT nome FROM lojas WHERE id = ?");
    $stmtLoja->execute([$lojaId]);
    $lojaNome = $stmtLoja->fetchColumn();

    $fornecedorNome = null;
    if ($fornecedorId) {
        $stmtF = $pdo->prepare("SELECT nome FROM fornecedores WHERE id = ?");
        $stmtF->execute([$fornecedorId]);
        $fornecedorNome = $stmtF->fetchColumn();
    }

    // apelidos: prioriza um apelido especifico deste fornecedor; se nao tiver,
    // aceita um apelido "geral" (aprendido pela contagem, sem fornecedor)
    $apelidos = $pdo->query("
        SELECT pa.apelido, pa.fornecedor_id, pa.produto_cmv_id, p.nome_padrao, p.unidade_padrao
        FROM produtos_apelidos pa JOIN produtos_cmv p ON p.id = pa.produto_cmv_id
    ")->fetchAll();
    $mapaEspecifico = []; $mapaGeral = [];
    foreach ($apelidos as $a) {
        $chave = normalizarTexto($a['apelido']);
        if ($a['fornecedor_id'] !== null && (int)$a['fornecedor_id'] === $fornecedorId) {
            $mapaEspecifico[$chave] = $a;
        } elseif ($a['fornecedor_id'] === null) {
            $mapaGeral[$chave] = $a;
        }
    }
    $produtos = $pdo->query("SELECT id, nome_padrao, unidade_padrao FROM produtos_cmv WHERE ativo=1")->fetchAll();
    foreach ($produtos as $p) {
        $chave = normalizarTexto($p['nome_padrao']);
        if (!isset($mapaGeral[$chave])) {
            $mapaGeral[$chave] = ['produto_cmv_id' => $p['id'], 'nome_padrao' => $p['nome_padrao'], 'unidade_padrao' => $p['unidade_padrao']];
        }
    }

    $stmtItens = $pdo->prepare("SELECT * FROM compras_itens WHERE loja_id=? AND nota_referencia=? AND data_compra=? AND vinculado=0
        AND (fornecedor_id <=> ?)");
    $stmtItens->execute([$lojaId, $notaRef, $data, $fornecedorId]);
    $itens = $stmtItens->fetchAll();

    foreach ($itens as &$it) {
        $chave = normalizarTexto($it['produto_texto_original']);
        $sugestao = $mapaEspecifico[$chave] ?? $mapaGeral[$chave] ?? null;
        $it['sugestao'] = $sugestao;
        $it['divergencia_unidade'] = $sugestao && strtoupper($sugestao['unidade_padrao']) !== strtoupper($it['unidade_original']);
    }

    echo json_encode(['ok' => true,
        'grupo' => ['loja_nome' => $lojaNome, 'fornecedor_nome' => $fornecedorNome, 'nota_referencia' => $notaRef, 'data_compra' => $data],
        'itens' => $itens], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
