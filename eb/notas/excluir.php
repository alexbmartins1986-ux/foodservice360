<?php
/* =====================================================================
   SOLICITAÇÃO DE EXCLUSÃO (nota suspeita / possível fraude)
   A nota NUNCA é apagada de verdade. Ela só muda de pasta/status,
   para existir um rastro caso precise investigar depois.
   ===================================================================== */

require __DIR__ . '/config.php';
require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/PHPMailer.php';
require __DIR__ . '/lib/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
date_default_timezone_set('America/Sao_Paulo');

$dados = json_decode(file_get_contents('php://input'), true);
$loja   = preg_replace('/[^a-z0-9]/', '', strtolower($dados['loja'] ?? ''));
$chave  = preg_replace('/[^0-9]/', '', $dados['chave'] ?? '');
$motivo = trim($dados['motivo'] ?? '');

if ($loja === '' || $chave === '' || $motivo === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Dados incompletos.']);
    exit;
}

$origem = __DIR__ . "/data/pendentes/$loja/$chave.json";
if (!file_exists($origem)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Esta nota não está mais pendente.']);
    exit;
}

$nota = json_decode(file_get_contents($origem), true);
$nota['status'] = 'exclusao_solicitada'; // vira 'cancelada' quando o financeiro confirmar (Fase 4)
$nota['motivoExclusao'] = $motivo;
$nota['exclusaoSolicitadaEm'] = date('c');

$lojaNome = $loja;
$lojasCad = json_decode(file_get_contents(__DIR__ . '/lojas.json'), true);
foreach (($lojasCad['lojas'] ?? []) as $l) { if ($l['id'] === $loja) $lojaNome = $l['nome']; }

// avisa o financeiro (por enquanto, por email) antes de mover a nota
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $EMAIL_CONTA;
    $mail->Password   = $EMAIL_SENHA;
    if ($SMTP_SEGURANCA !== '') { $mail->SMTPSecure = $SMTP_SEGURANCA; }
    $mail->Port       = $SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

    $mail->setFrom($EMAIL_CONTA, "Notas $lojaNome");
    $mail->addAddress($EMAIL_DESTINO);
    $mail->Subject = "[ATENCAO] Nota suspeita - $lojaNome - Nº {$nota['numero']} - aguardando exclusão";
    $mail->Body =
        "O gerente marcou esta nota como suspeita (possível fraude) e pediu para excluí-la da lista de contagem.\n\n" .
        "Loja: $lojaNome\n" .
        "Fornecedor: {$nota['fornecedorNome']} (CNPJ {$nota['fornecedorCnpj']})\n" .
        "Numero: {$nota['numero']}\n" .
        "Valor: R$ {$nota['valorTotal']}\n" .
        "Motivo informado: $motivo\n\n" .
        "AÇÃO NECESSÁRIA: confirme que esta nota NÃO deve ser paga pelo financeiro.\n" .
        "A nota fica registrada como pendente de exclusão (nada é apagado de verdade, " .
        "fica guardada para consulta futura caso seja preciso investigar).";
    $mail->send();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao enviar o alerta por email: ' . $mail->ErrorInfo]);
    exit;
}

// só move a nota DEPOIS do alerta sair com sucesso
$pastaExclusao = __DIR__ . "/data/pendente-exclusao/$loja";
if (!is_dir($pastaExclusao)) {
    @mkdir($pastaExclusao, 0700, true);
    @file_put_contents(__DIR__ . "/data/pendente-exclusao/.htaccess", "Require all denied\n");
    @file_put_contents(__DIR__ . "/data/pendente-exclusao/index.php", "<?php\n");
}
file_put_contents("$pastaExclusao/$chave.json", json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
@unlink($origem);

echo json_encode(['ok' => true]);
