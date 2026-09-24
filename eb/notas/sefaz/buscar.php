<?php
/* =====================================================================
   BUSCA AS NOTAS NOVAS NA SEFAZ E DEIXA PENDENTES PARA O GERENTE VALIDAR.
   Pode ser aberto manualmente (navegador) ou agendado (Cron Job).
   ===================================================================== */

require __DIR__ . '/config.php';
require __DIR__ . '/cliente.php';
require __DIR__ . '/../lib/ler_nota.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
date_default_timezone_set('America/Sao_Paulo');

$arqEstado = __DIR__ . '/ultimo_nsu.txt';
$ultNSU = is_file($arqEstado) ? trim(file_get_contents($arqEstado)) : '0';

$cfg = [
    'arquivo'  => $SEFAZ_CERT_ARQUIVO,
    'senha'    => $SEFAZ_CERT_SENHA,
    'ambiente' => $SEFAZ_AMBIENTE,
    'uf'       => $SEFAZ_UF,
    'cnpj'     => $SEFAZ_CNPJ,
];

$resultado = consultarSefazDFe($cfg, $ultNSU);

if (!$resultado['ok']) {
    echo json_encode(['ok' => false, 'erro' => $resultado['erro']], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($resultado['semNovidade'])) {
    echo json_encode(['ok' => true, 'novasNotas' => 0, 'mensagem' => 'Nenhuma nota nova desde a última busca.']);
    exit;
}

$pastaPendentes = __DIR__ . "/../data/pendentes/$SEFAZ_LOJA_DESTINO";
if (!is_dir($pastaPendentes)) { @mkdir($pastaPendentes, 0700, true); }

$processadas = 0;
$ignoradas = 0;
$erros = [];

foreach ($resultado['documentos'] as $doc) {
    // resNFe é só um resumo (sem itens); só processamos o documento completo (procNFe)
    if (strpos($doc['schema'], 'resNFe') !== false) {
        $ignoradas++;
        continue;
    }

    $nota = lerNotaXMLTexto($doc['xml']);
    if (!$nota['ok']) {
        $erros[] = $nota['erro'];
        continue;
    }

    $destino = "$pastaPendentes/{$nota['chave']}.json";
    if (file_exists($destino)) { continue; } // já processada antes, não duplica

    $nota['loja'] = $SEFAZ_LOJA_DESTINO;
    $nota['recebidaEm'] = date('c');
    $nota['origem'] = 'sefaz';
    file_put_contents($destino, json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $processadas++;
}

// só avança o NSU depois de processar tudo com sucesso
if (!empty($resultado['ultNSU'])) {
    file_put_contents($arqEstado, $resultado['ultNSU']);
}

echo json_encode([
    'ok' => true,
    'novasNotas' => $processadas,
    'resumosIgnorados' => $ignoradas,
    'erros' => $erros,
    'ultNSU' => $resultado['ultNSU'] ?? $ultNSU,
], JSON_UNESCAPED_UNICODE);
