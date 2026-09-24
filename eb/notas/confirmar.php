<?php
/* =====================================================================
   CONFIRMA UMA NOTA PENDENTE (XML/Sefaz/manual não paga): monta a
   planilha, envia por email, e arquiva. Não precisa editar.
   ===================================================================== */

require __DIR__ . '/config.php';
require __DIR__ . '/lib/confirmar_nota.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
date_default_timezone_set('America/Sao_Paulo');

$dados = json_decode(file_get_contents('php://input'), true);
$loja   = preg_replace('/[^a-z0-9]/', '', strtolower($dados['loja'] ?? ''));
$chave  = preg_replace('/[^0-9]/', '', $dados['chave'] ?? '');
$vencimento = $dados['vencimento'] ?? '';
$divergencias = $dados['divergencias'] ?? [];
$bonificacoes = $dados['bonificacoes'] ?? [];
$transferencias = $dados['transferencias'] ?? [];

if ($loja === '' || $chave === '' || $vencimento === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Dados incompletos.']);
    exit;
}

$origem = __DIR__ . "/data/pendentes/$loja/$chave.json";
if (!file_exists($origem)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Esta nota não está mais pendente (pode já ter sido confirmada).']);
    exit;
}

$nota = json_decode(file_get_contents($origem), true);

$lojaNome = $loja;
$lojasCad = json_decode(file_get_contents(__DIR__ . '/lojas.json'), true);
foreach (($lojasCad['lojas'] ?? []) as $l) { if ($l['id'] === $loja) $lojaNome = $l['nome']; }

$emailCfg = ['conta' => $EMAIL_CONTA, 'senha' => $EMAIL_SENHA, 'destino' => $EMAIL_DESTINO,
             'host' => $SMTP_HOST, 'porta' => $SMTP_PORT, 'seguranca' => $SMTP_SEGURANCA];

$resultado = confirmarNotaEEnviar($nota, $lojaNome, $vencimento, $divergencias, $bonificacoes, $transferencias, $emailCfg);

if (!$resultado['ok']) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $resultado['erro']]);
    exit;
}

// só arquiva DEPOIS do email sair com sucesso
$nota['confirmadaEm'] = date('c');
$nota['vencimento'] = $vencimento;
$nota['divergencias'] = $divergencias;
$nota['bonificacoes'] = $bonificacoes;
$nota['transferencias'] = $transferencias;

$pastaConfirmadas = __DIR__ . "/data/confirmadas/$loja";
if (!is_dir($pastaConfirmadas)) {
    @mkdir($pastaConfirmadas, 0700, true);
    @file_put_contents(__DIR__ . '/data/confirmadas/.htaccess', "Require all denied\n");
    @file_put_contents(__DIR__ . '/data/confirmadas/index.php', "<?php\n");
}
file_put_contents("$pastaConfirmadas/$chave.json", json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
@file_put_contents("$pastaConfirmadas/{$chave}.csv", $resultado['csv']);
@unlink($origem);

echo json_encode(['ok' => true]);
