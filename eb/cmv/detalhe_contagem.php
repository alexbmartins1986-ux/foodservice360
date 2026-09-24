<?php
require __DIR__ . '/../db/conexao.php';
require __DIR__ . '/lib/texto.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['ok' => false, 'erro' => 'id não informado']); exit; }

try {
    $pdo = conectarBanco();

    $stmt = $pdo->prepare("
        SELECT c.id, c.setor, c.responsavel, c.enviada_em, l.nome AS loja_nome
        FROM contagens c JOIN lojas l ON l.id = c.loja_id WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $contagem = $stmt->fetch();
    if (!$contagem) { echo json_encode(['ok' => false, 'erro' => 'Contagem não encontrada.']); exit; }

    // todos os apelidos já conhecidos, pra sugerir vínculo automático
    $apelidos = $pdo->query("
        SELECT pa.apelido, pa.produto_cmv_id, p.nome_padrao, p.unidade_padrao
        FROM produtos_apelidos pa JOIN produtos_cmv p ON p.id = pa.produto_cmv_id
    ")->fetchAll();
    $mapaApelidos = [];
    foreach ($apelidos as $a) { $mapaApelidos[normalizarTexto($a['apelido'])] = $a; }
    // também considera o próprio nome_padrao como um "apelido" válido de si mesmo
    $produtos = $pdo->query("SELECT id, nome_padrao, unidade_padrao FROM produtos_cmv WHERE ativo=1")->fetchAll();
    foreach ($produtos as $p) {
        $chave = normalizarTexto($p['nome_padrao']);
        if (!isset($mapaApelidos[$chave])) {
            $mapaApelidos[$chave] = ['produto_cmv_id' => $p['id'], 'nome_padrao' => $p['nome_padrao'], 'unidade_padrao' => $p['unidade_padrao']];
        }
    }

    $stmtItens = $pdo->prepare("SELECT * FROM contagens_itens WHERE contagem_id = ?");
    $stmtItens->execute([$id]);
    $itens = $stmtItens->fetchAll();

    foreach ($itens as &$it) {
        $chave = normalizarTexto($it['produto_texto_original']);
        $sugestao = $mapaApelidos[$chave] ?? null;
        $it['sugestao'] = $sugestao;
        $it['divergencia_unidade'] = $sugestao && strtoupper($sugestao['unidade_padrao']) !== strtoupper($it['unidade_original']);
    }

    echo json_encode(['ok' => true, 'contagem' => $contagem, 'itens' => $itens], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
