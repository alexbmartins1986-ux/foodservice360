<?php
/* =====================================================================
   LEITURA DO XML DA NOTA FISCAL (NFe)
   Transforma o arquivo XML em um array PHP organizado.
   Não precisa editar este arquivo.
   ===================================================================== */

function lerNotaXML(string $caminhoArquivo): array {
    return lerNotaXMLTexto(file_get_contents($caminhoArquivo));
}

function lerNotaXMLTexto(string $conteudoXML): array {
    $dom = new DOMDocument();
    $anterior = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($conteudoXML);
    libxml_use_internal_errors($anterior);
    if (!$ok) {
        return ['ok' => false, 'erro' => 'Arquivo não é um XML válido.'];
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');

    $infNFe = $xp->query('//n:infNFe')->item(0);
    if (!$infNFe) {
        return ['ok' => false, 'erro' => 'Não encontrei os dados da nota dentro do XML (infNFe ausente). Confirme se é um XML de NFe.'];
    }

    // chave de acesso vem no atributo Id, tipo "NFe35260612345..."
    $chave = preg_replace('/[^0-9]/', '', $infNFe->getAttribute('Id'));

    $texto = function ($caminho) use ($xp, $infNFe) {
        $n = $xp->query($caminho, $infNFe)->item(0);
        return $n ? trim($n->textContent) : '';
    };

    $numero   = $texto('n:ide/n:nNF');
    $serie    = $texto('n:ide/n:serie');
    $emissao  = $texto('n:ide/n:dhEmi') ?: $texto('n:ide/n:dEmi');
    $fornCnpj = $texto('n:emit/n:CNPJ');
    $fornNome = $texto('n:emit/n:xFant') ?: $texto('n:emit/n:xNome');
    $valorTot = $texto('n:total/n:ICMSTot/n:vNF');
    $vencimento = $texto('n:cobr/n:dup[1]/n:dVenc'); // 1ª parcela, só como sugestão
    $qtdParcelas = $xp->query('n:cobr/n:dup', $infNFe)->length;

    $itens = [];
    foreach ($xp->query('n:det', $infNFe) as $det) {
        $prod = $xp->query('n:prod', $det)->item(0);
        if (!$prod) continue;
        $campo = function ($tag) use ($xp, $prod) {
            $n = $xp->query("n:$tag", $prod)->item(0);
            return $n ? trim($n->textContent) : '';
        };
        $itens[] = [
            'produto'   => $campo('xProd'),
            'unidade'   => $campo('uCom'),
            'qtd'       => $campo('qCom'),
            'valorUnit' => $campo('vUnCom'),
            'valorItem' => $campo('vProd'),
        ];
    }

    if ($numero === '' || empty($itens)) {
        return ['ok' => false, 'erro' => 'XML lido, mas faltam dados essenciais (número da nota ou itens). Pode ser um formato diferente do esperado.'];
    }

    return [
        'ok' => true,
        'chave' => $chave,
        'numero' => $numero,
        'serie' => $serie,
        'emissao' => $emissao,
        'fornecedorNome' => $fornNome,
        'fornecedorCnpj' => $fornCnpj,
        'valorTotal' => $valorTot,
        'vencimentoSugerido' => $vencimento,
        'parcelas' => $qtdParcelas,
        'itens' => $itens,
    ];
}
