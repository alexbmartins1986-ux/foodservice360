<?php
/* =====================================================================
   RECEBE A CONTAGEM, GERA A PLANILHA (CSV), SALVA CÓPIA E ENVIA POR EMAIL
   Não precisa editar nada aqui. As configurações ficam no config.php.
   ===================================================================== */

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falta o arquivo eb/config.php neste ambiente (copie de config.example.php e preencha o email).']);
    exit;
}
require __DIR__ . '/config.php';
require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/PHPMailer.php';
require __DIR__ . '/lib/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Sao_Paulo');

// monta uma linha de CSV (separador ; e aspas, padrão Excel Brasil)
function csvLinha($campos) {
    $out = [];
    foreach ($campos as $c) {
        $out[] = '"' . str_replace('"', '""', (string)$c) . '"';
    }
    return implode(';', $out) . "\r\n";
}

// 1. Recebe os dados que a tela enviou (formato JSON)
$dados = json_decode(file_get_contents('php://input'), true);
if (!$dados || empty($dados['itens'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Nenhum dado recebido.']);
    exit;
}

$casa  = $dados['casa']  ?? 'Casa';
$setor = $dados['setor'] ?? 'Setor';
$resp  = $dados['responsavel'] ?? '';

// 2. Nome do arquivo com a data de HOJE (horário de Brasília)
$casaLimpa  = preg_replace('/[^A-Za-z0-9]/', '', $casa);
$setorLimpo = preg_replace('/[^A-Za-z0-9]/', '', $setor);
$nomeArq = "Contagem_{$casaLimpa}_{$setorLimpo}_" . date('Y-m-d_H\hi') . ".csv";

// 3. Monta o conteúdo da planilha
$csv  = "\xEF\xBB\xBF"; // marca pro Excel abrir os acentos certo
$csv .= csvLinha(['Contagem de Estoque', "$casa - $setor"]);
$csv .= csvLinha(['Data', date('d/m/Y H:i')]);
$csv .= csvLinha(['Responsavel', $resp]);
$csv .= "\r\n";
$csv .= csvLinha(['Categoria', 'Produto', 'Unidade', 'Quantidade', 'Observacao']);
foreach ($dados['itens'] as $it) {
    $csv .= csvLinha([
        $it['categoria'] ?? '', $it['produto'] ?? '', $it['unidade'] ?? '',
        $it['qtd'] ?? '', $it['obs'] ?? ''
    ]);
}

// pendências (correções e itens novos), se houver
$correcoes = $dados['correcoes'] ?? [];
$novos     = $dados['novos'] ?? [];
if ($correcoes || $novos) {
    $csv .= "\r\n";
    $csv .= csvLinha(['PENDENCIAS PARA APROVACAO']);
    $csv .= csvLinha(['Produto', 'Tipo', 'Detalhe']);
    foreach ($correcoes as $c) {
        $tipo = ['nome' => 'Corrigir nome', 'unidade' => 'Corrigir unidade', 'excluir' => 'Excluir item'][$c['tipo']] ?? $c['tipo'];
        $csv .= csvLinha([$c['produto'] ?? '', $tipo, $c['valor'] ?? '']);
    }
    foreach ($novos as $n) {
        $csv .= csvLinha(['(NOVO) ' . ($n['nome'] ?? ''), 'Inserir item', ($n['un'] ?? '') . '  ' . ($n['motivo'] ?? '')]);
    }
}

// 4. Salva uma cópia no servidor (backup local, numa pasta trancada)
$pasta = __DIR__ . '/backups';
if (!is_dir($pasta)) { @mkdir($pasta, 0700, true); }
@file_put_contents($pasta . '/' . $nomeArq, $csv);
// salva também uma versão estruturada (JSON), pra outros scripts lerem depois
$dadosSalvos = ['casa' => $casa, 'setor' => $setor, 'responsavel' => $resp,
                'enviadaEm' => date('c'), 'itens' => $dados['itens']];
@file_put_contents($pasta . '/' . preg_replace('/\.csv$/', '.json', $nomeArq),
    json_encode($dadosSalvos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// 4.5. Tenta salvar no banco de dados novo (opcional, nunca quebra o envio
//      se o banco ainda não estiver configurado, como é o caso na produção hoje)
try {
    if (file_exists(__DIR__ . '/db/config.php') && file_exists(__DIR__ . '/db/conexao.php')) {
        require_once __DIR__ . '/db/conexao.php';
        $pdo = conectarBanco();

        // hoje so existe 1 cliente no sistema inteiro; usa o que ja existir,
        // sem depender do texto do nome bater exatamente entre telas diferentes
        $clienteId = $pdo->query("SELECT id FROM clientes ORDER BY id LIMIT 1")->fetchColumn();
        if (!$clienteId) {
            $pdo->prepare("INSERT INTO clientes (nome) VALUES (?)")->execute([$casa]);
            $clienteId = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("SELECT id FROM lojas WHERE cliente_id = ? AND codigo = ?");
        $stmt->execute([$clienteId, 'loja1']);
        $lojaId = $stmt->fetchColumn();
        if (!$lojaId) {
            $pdo->prepare("INSERT INTO lojas (cliente_id, codigo, nome) VALUES (?, 'loja1', ?)")
                ->execute([$clienteId, $casa]);
            $lojaId = $pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO contagens (loja_id, setor, responsavel, enviada_em) VALUES (?, ?, ?, NOW())")
            ->execute([$lojaId, $setor, $resp]);
        $contagemId = $pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO contagens_itens (contagem_id, produto_texto_original, unidade_original, quantidade) VALUES (?, ?, ?, ?)");
        foreach ($dados['itens'] as $it) {
            $stmtItem->execute([$contagemId, $it['produto'] ?? '', $it['unidade'] ?? '', $it['qtd'] ?? 0]);
        }
    }
} catch (Throwable $e) {
    // banco fora do ar ou não configurado: tudo bem, o email continua sendo enviado normalmente
}

// 5. Envia por email com a planilha anexada
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
    // evita travar por certificado no envio local
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

    $mail->setFrom($EMAIL_CONTA, "Contagem $casa");
    $mail->addAddress($EMAIL_DESTINO);
    $mail->Subject = "Contagem $setor - $casa - " . date('d/m/Y');
    $mail->Body    = "Contagem enviada por " . ($resp ?: 'equipe') . ".\n"
                   . "Casa: $casa\nSetor: $setor\nData: " . date('d/m/Y H:i') . "\n\nPlanilha em anexo.";
    $mail->addStringAttachment($csv, $nomeArq);
    $mail->send();

    echo json_encode(['ok' => true, 'arquivo' => $nomeArq]);
} catch (Exception $e) {
    // o email falhou, mas a cópia local já foi salva no passo 4
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha no envio do email: ' . $mail->ErrorInfo]);
}
