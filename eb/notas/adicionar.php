<?php
/* =====================================================================
   RECEBE UMA NOTA DE COMPRA AVULSA (digitada + foto). Como é a própria
   gerente lançando, ela já sai CONFIRMADA na hora (sem passar pela
   fila de pendência). Se o email falhar, cai numa pendência de
   segurança, pra nada se perder.
   ===================================================================== */

require __DIR__ . '/config.php';
require __DIR__ . '/lib/confirmar_nota.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
date_default_timezone_set('America/Sao_Paulo');

$loja = preg_replace('/[^a-z0-9]/', '', strtolower($_POST['loja'] ?? ''));
$fornecedor = trim($_POST['fornecedor'] ?? '');
$data = $_POST['data'] ?? '';
$itensJson = $_POST['itens'] ?? '[]';
$formaPagamento = trim($_POST['formaPagamento'] ?? '');
$identificacaoPagamento = trim($_POST['identificacaoPagamento'] ?? '');
$pago = ($_POST['pago'] ?? '1') === '1';
$vencimento = $_POST['vencimento'] ?? '';

$itens = json_decode($itensJson, true);
if (!is_array($itens) || empty($itens)) {
    echo json_encode(['ok' => false, 'erro' => 'Nenhum item recebido.']);
    exit;
}
foreach ($itens as $it) {
    $produto = trim($it['produto'] ?? '');
    $qtd = trim((string)($it['qtd'] ?? ''));
    $valorItem = trim((string)($it['valorItem'] ?? ''));
    if ($produto === '' || $qtd === '' || $valorItem === '') {
        echo json_encode(['ok' => false, 'erro' => 'Há um item incompleto (falta produto, quantidade ou valor).']);
        exit;
    }
}
if ($loja === '' || $fornecedor === '' || $data === '') {
    echo json_encode(['ok' => false, 'erro' => 'Fornecedor e data são obrigatórios.']);
    exit;
}
if (!$pago && $vencimento === '') {
    echo json_encode(['ok' => false, 'erro' => 'Informe a data de vencimento, já que ainda não foi pago.']);
    exit;
}
if ($identificacaoPagamento === '') {
    echo json_encode(['ok' => false, 'erro' => 'A identificação da forma de pagamento é obrigatória.']);
    exit;
}

// ---- foto: obrigatória, validada como imagem de verdade ----
if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'erro' => 'A foto da nota é obrigatória e não chegou corretamente.']);
    exit;
}
$infoImg = @getimagesize($_FILES['foto']['tmp_name']);
if (!$infoImg) {
    echo json_encode(['ok' => false, 'erro' => 'O arquivo enviado não parece ser uma imagem válida.']);
    exit;
}
$extPorTipo = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
$ext = $extPorTipo[$infoImg[2]] ?? 'jpg';

$chave = date('YmdHis') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

$pastaFotos = __DIR__ . "/data/fotos/$loja";
if (!is_dir($pastaFotos)) {
    @mkdir($pastaFotos, 0755, true);
    @file_put_contents(__DIR__ . '/data/fotos/.htaccess', "Options -Indexes\n");
}
$nomeFoto = $chave . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
move_uploaded_file($_FILES['foto']['tmp_name'], "$pastaFotos/$nomeFoto");

$valorTotal = 0;
$itensLimpos = [];
foreach ($itens as $it) {
    $qtd = (float)str_replace(',', '.', (string)($it['qtd'] ?? 0));
    $valorItem = (float)str_replace(',', '.', (string)($it['valorItem'] ?? 0));
    $valorTotal += $valorItem;
    $itensLimpos[] = [
        'produto' => trim($it['produto'] ?? ''),
        'unidade' => trim($it['unidade'] ?? ''),
        'qtd' => (string)$qtd,
        'valorUnit' => $qtd > 0 ? number_format($valorItem / $qtd, 4, '.', '') : '0',
        'valorItem' => number_format($valorItem, 2, '.', ''),
    ];
}

$nota = [
    'chave' => $chave,
    'numero' => 'AVULSA-' . substr($chave, -6),
    'serie' => '-',
    'emissao' => $data . 'T00:00:00-03:00',
    'fornecedorNome' => $fornecedor,
    'fornecedorCnpj' => '(compra avulsa)',
    'valorTotal' => number_format($valorTotal, 2, '.', ''),
    'itens' => $itensLimpos,
    'loja' => $loja,
    'recebidaEm' => date('c'),
    'origem' => 'manual',
    'formaPagamento' => $formaPagamento,
    'identificacaoPagamento' => $identificacaoPagamento,
    'fotoNota' => "data/fotos/$loja/$nomeFoto",
];

$lojaNome = $loja;
$lojasCad = json_decode(file_get_contents(__DIR__ . '/lojas.json'), true);
foreach (($lojasCad['lojas'] ?? []) as $l) { if ($l['id'] === $loja) $lojaNome = $l['nome']; }

$emailCfg = ['conta' => $EMAIL_CONTA, 'senha' => $EMAIL_SENHA, 'destino' => $EMAIL_DESTINO,
             'host' => $SMTP_HOST, 'porta' => $SMTP_PORT, 'seguranca' => $SMTP_SEGURANCA];

// se pago, a "data de vencimento" pra fins de registro é a propria data da compra
$vencimentoParaEnvio = $pago ? $data : $vencimento;
$resultado = confirmarNotaEEnviar($nota, $lojaNome, $vencimentoParaEnvio, [], [], [], $emailCfg);

if ($resultado['ok']) {
    $nota['confirmadaEm'] = date('c');
    $nota['vencimento'] = $vencimentoParaEnvio;
    $nota['pagoNoAto'] = $pago;
    $pastaConfirmadas = __DIR__ . "/data/confirmadas/$loja";
    if (!is_dir($pastaConfirmadas)) {
        @mkdir($pastaConfirmadas, 0700, true);
        @file_put_contents(__DIR__ . '/data/confirmadas/.htaccess', "Require all denied\n");
        @file_put_contents(__DIR__ . '/data/confirmadas/index.php', "<?php\n");
    }
    file_put_contents("$pastaConfirmadas/$chave.json", json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @file_put_contents("$pastaConfirmadas/{$chave}.csv", $resultado['csv']);
    echo json_encode(['ok' => true, 'enviado' => true]);
} else {
    // email falhou: nao perde a nota, ela cai como pendente pra tentar de novo depois
    $nota['vencimentoSugerido'] = $vencimentoParaEnvio;
    $pastaPendentes = __DIR__ . "/data/pendentes/$loja";
    if (!is_dir($pastaPendentes)) {
        @mkdir($pastaPendentes, 0700, true);
        @file_put_contents(__DIR__ . '/data/pendentes/.htaccess', "Require all denied\n");
        @file_put_contents(__DIR__ . '/data/pendentes/index.php', "<?php\n");
    }
    file_put_contents("$pastaPendentes/$chave.json", json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true, 'enviado' => false, 'aviso' => 'A nota foi salva, mas o email falhou (' . $resultado['erro'] . '). Ela ficou pendente para tentar de novo pela lista.']);
}
