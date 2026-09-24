<?php
require __DIR__ . '/lib/ler_nota.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

$loja = preg_replace('/[^a-z0-9]/', '', strtolower($_POST['loja'] ?? ''));
if ($loja === '') {
    echo json_encode(['erroGeral' => 'Loja não informada.']);
    exit;
}

$pastaPendentes = __DIR__ . "/data/pendentes/$loja";
if (!is_dir($pastaPendentes)) {
    @mkdir($pastaPendentes, 0700, true);
    @file_put_contents(__DIR__ . '/data/pendentes/.htaccess', "Require all denied\n");
    @file_put_contents(__DIR__ . '/data/pendentes/index.php', "<?php\n");
}

$resultados = [];
$arquivos = $_FILES['xmls'] ?? null;
if (!$arquivos) {
    echo json_encode(['erroGeral' => 'Nenhum arquivo recebido.']);
    exit;
}

$n = count($arquivos['name']);
for ($i = 0; $i < $n; $i++) {
    $nomeOriginal = $arquivos['name'][$i];
    $tmp = $arquivos['tmp_name'][$i];

    if ($arquivos['error'][$i] !== UPLOAD_ERR_OK) {
        $resultados[] = ['ok' => false, 'arquivo' => $nomeOriginal, 'erro' => 'Falha no upload deste arquivo.'];
        continue;
    }

    $nota = lerNotaXML($tmp);
    if (!$nota['ok']) {
        $resultados[] = ['ok' => false, 'arquivo' => $nomeOriginal, 'erro' => $nota['erro']];
        continue;
    }

    // evita duplicar a mesma nota se ela já estiver pendente
    $destino = $pastaPendentes . '/' . $nota['chave'] . '.json';
    if (file_exists($destino)) {
        $resultados[] = ['ok' => false, 'arquivo' => $nomeOriginal, 'erro' => 'Esta nota já estava na lista pendente (não duplicada).'];
        continue;
    }

    $nota['loja'] = $loja;
    $nota['recebidaEm'] = date('c');
    file_put_contents($destino, json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $resultados[] = [
        'ok' => true, 'arquivo' => $nomeOriginal,
        'numero' => $nota['numero'], 'fornecedor' => $nota['fornecedorNome']
    ];
}

echo json_encode(['resultados' => $resultados]);
