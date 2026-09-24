<?php
/* =====================================================================
   LÓGICA COMPARTILHADA: monta a planilha da nota e envia por email.
   Usada tanto quando a gerente confirma uma nota pendente quanto
   quando uma compra avulsa é lançada (que já sai confirmada direto).
   Não precisa editar este arquivo.
   ===================================================================== */

require_once __DIR__ . '/Exception.php';
require_once __DIR__ . '/PHPMailer.php';
require_once __DIR__ . '/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// formata numero com VIRGULA decimal, do jeito que o Excel brasileiro espera
// num CSV separado por ponto-e-virgula. Sem isso, o Excel trata como texto.
function numeroBR($valor, $casas = 2) {
    return number_format((float)$valor, $casas, ',', '');
}

function csvLinha($campos) {
    $out = [];
    foreach ($campos as $c) { $out[] = '"' . str_replace('"', '""', (string)$c) . '"'; }
    return implode(';', $out) . "\r\n";
}

/**
 * Monta a planilha, envia por email, e retorna o resultado.
 * NÃO mexe em arquivos/pastas, isso fica a cargo de quem chama.
 */
function confirmarNotaEEnviar(array $nota, string $lojaNome, string $vencimento,
    array $divergencias, array $bonificacoes, array $transferencias,
    array $emailCfg): array {

    $csv  = "\xEF\xBB\xBF";
    $csv .= csvLinha(['Nota Fiscal Confirmada']);
    $csv .= csvLinha(['Loja', $lojaNome]);
    $csv .= csvLinha(['Fornecedor', $nota['fornecedorNome'], 'CNPJ', $nota['fornecedorCnpj']]);
    $csv .= csvLinha(['Numero', $nota['numero'], 'Serie', $nota['serie']]);
    $csv .= csvLinha(['Emissao', $nota['emissao']]);
    $csv .= csvLinha(['Recebimento confirmado em', date('d/m/Y H:i')]);
    $csv .= csvLinha(['Vencimento', $vencimento ? date('d/m/Y', strtotime($vencimento)) : 'Pago no ato']);
    $csv .= csvLinha(['Valor total', numeroBR($nota['valorTotal'])]);
    if (!empty($nota['formaPagamento'])) {
        $csv .= csvLinha(['Forma de pagamento', $nota['formaPagamento'], 'Identificacao', $nota['identificacaoPagamento'] ?? '']);
    }
    $csv .= "\r\n" . csvLinha(['Produto', 'Unidade', 'Quantidade', 'Valor unitario (R$)', 'Valor total (R$)', 'Situacao']);

    $bonifPorProduto = array_column($bonificacoes, null, 'produto');
    $transfPorProduto = array_column($transferencias, null, 'produto');
    foreach ($nota['itens'] as $it) {
        $situacao = 'Normal';
        if (isset($bonifPorProduto[$it['produto']])) $situacao = 'BONIFICACAO (nao entra no custo)';
        elseif (isset($transfPorProduto[$it['produto']])) $situacao = 'TRANSFERENCIA para ' . $transfPorProduto[$it['produto']]['lojaDestinoNome'];
        $csv .= csvLinha([
            $it['produto'], $it['unidade'], numeroBR($it['qtd'], 3),
            numeroBR($it['valorUnit'], 4), numeroBR($it['valorItem']), $situacao
        ]);
    }
    if ($divergencias) {
        $csv .= "\r\n" . csvLinha(['DIVERGENCIAS RELATADAS PELO GERENTE']);
        $csv .= csvLinha(['Produto', 'Observacao']);
        foreach ($divergencias as $d) { $csv .= csvLinha([$d['produto'] ?? '', $d['texto'] ?? '']); }
    }
    if ($bonificacoes || $transferencias) {
        $csv .= "\r\n" . csvLinha(['PENDENCIAS PARA APROVACAO (bonificacao / transferencia)']);
        $csv .= csvLinha(['Produto', 'Tipo', 'Detalhe']);
        foreach ($bonificacoes as $b) { $csv .= csvLinha([$b['produto'] ?? '', 'Bonificacao', 'Excluir do custo desta loja']); }
        foreach ($transferencias as $t) { $csv .= csvLinha([$t['produto'] ?? '', 'Transferencia', 'Mover custo para: ' . ($t['lojaDestinoNome'] ?? '')]); }
    }

    $nomeArq = "Nota_{$lojaNome}_{$nota['numero']}_" . date('Y-m-d') . ".csv";
    $nomeArq = preg_replace('/[^A-Za-z0-9_.-]/', '_', $nomeArq);

    // tenta salvar no banco novo ANTES de tentar o email (opcional, nunca
    // quebra se o banco nao estiver configurado, como acontece na producao hoje;
    // e assim o dado da compra nao se perde mesmo se o email falhar)
    try {
        $dbConfig = __DIR__ . '/../../db/config.php';
        $dbConexao = __DIR__ . '/../../db/conexao.php';
        if (file_exists($dbConfig) && file_exists($dbConexao)) {
            require_once $dbConexao;
            $pdo = conectarBanco();

            // hoje so existe 1 cliente no sistema inteiro; usa o que ja existir,
            // sem depender do texto do nome bater exatamente entre telas diferentes
            $clienteId = $pdo->query("SELECT id FROM clientes ORDER BY id LIMIT 1")->fetchColumn();
            if (!$clienteId) {
                $pdo->prepare("INSERT INTO clientes (nome) VALUES (?)")->execute([$lojaNome]);
                $clienteId = $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare("SELECT id FROM lojas WHERE cliente_id = ? AND codigo = ?");
            $stmt->execute([$clienteId, $nota['loja'] ?? 'loja1']);
            $lojaId = $stmt->fetchColumn();
            if (!$lojaId) {
                $pdo->prepare("INSERT INTO lojas (cliente_id, codigo, nome) VALUES (?, ?, ?)")
                    ->execute([$clienteId, $nota['loja'] ?? 'loja1', $lojaNome]);
                $lojaId = $pdo->lastInsertId();
            }

            $fornecedorId = null;
            $nomeForn = trim($nota['fornecedorNome'] ?? '');
            if ($nomeForn !== '') {
                $stmt = $pdo->prepare("SELECT id FROM fornecedores WHERE cliente_id = ? AND nome = ?");
                $stmt->execute([$clienteId, $nomeForn]);
                $fornecedorId = $stmt->fetchColumn();
                if (!$fornecedorId) {
                    $pdo->prepare("INSERT INTO fornecedores (cliente_id, nome, cnpj) VALUES (?, ?, ?)")
                        ->execute([$clienteId, $nomeForn, $nota['fornecedorCnpj'] ?? null]);
                    $fornecedorId = $pdo->lastInsertId();
                }
            }

            $dataCompra = date('Y-m-d', strtotime($nota['emissao'] ?? 'now'));
            $notaRef = $nota['numero'] ?? ($nota['chave'] ?? '');
            $stmtItem = $pdo->prepare("INSERT INTO compras_itens
                (loja_id, fornecedor_id, nota_referencia, produto_texto_original, unidade_original, quantidade, valor_unitario, valor_total, data_compra)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($nota['itens'] as $it) {
                $stmtItem->execute([
                    $lojaId, $fornecedorId, $notaRef,
                    $it['produto'] ?? '', $it['unidade'] ?? '',
                    $it['qtd'] ?? 0, $it['valorUnit'] ?? 0, $it['valorItem'] ?? 0, $dataCompra
                ]);
            }
        }
    } catch (Throwable $e) {
        // banco fora do ar ou nao configurado: tudo bem, o email continua normal
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $emailCfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $emailCfg['conta'];
        $mail->Password   = $emailCfg['senha'];
        if ($emailCfg['seguranca'] !== '') { $mail->SMTPSecure = $emailCfg['seguranca']; }
        $mail->Port       = $emailCfg['porta'];
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

        $mail->setFrom($emailCfg['conta'], "Notas $lojaNome");
        $mail->addAddress($emailCfg['destino']);
        $temPendencia = $divergencias || $bonificacoes || $transferencias;
        $mail->Subject = ($temPendencia ? "[PENDENCIA] " : "") . "Nota confirmada - $lojaNome - Nº {$nota['numero']} - " . date('d/m/Y');
        $corpo = "Nota fiscal confirmada.\n\nLoja: $lojaNome\nFornecedor: {$nota['fornecedorNome']}\n"
               . "Numero: {$nota['numero']}\nValor: R$ " . numeroBR($nota['valorTotal']) . "\n"
               . "Vencimento: " . ($vencimento ? date('d/m/Y', strtotime($vencimento)) : 'Pago no ato') . "\n";
        if (!empty($nota['formaPagamento'])) { $corpo .= "Forma de pagamento: {$nota['formaPagamento']} {$nota['identificacaoPagamento']}\n"; }
        if ($divergencias) { $corpo .= "\nATENCAO: " . count($divergencias) . " item(ns) com divergencia relatada, ver planilha em anexo.\n"; }
        if ($bonificacoes) { $corpo .= "\nATENCAO: " . count($bonificacoes) . " item(ns) marcado(s) como BONIFICACAO, aguardando sua aprovacao para excluir do custo.\n"; }
        if ($transferencias) { $corpo .= "\nATENCAO: " . count($transferencias) . " item(ns) marcado(s) para TRANSFERENCIA entre lojas, aguardando sua aprovacao.\n"; }
        $mail->Body = $corpo;
        $mail->addStringAttachment($csv, $nomeArq);
        $mail->send();
    } catch (Exception $e) {
        return ['ok' => false, 'erro' => 'Falha no envio do email: ' . $mail->ErrorInfo, 'csv' => $csv];
    }

    return ['ok' => true, 'csv' => $csv, 'nomeArquivo' => $nomeArq];
}
