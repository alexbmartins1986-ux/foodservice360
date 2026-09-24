<?php
/* =====================================================================
   CLIENTE DA DISTRIBUIÇÃO DFe (busca automática de notas na Sefaz)
   Não precisa editar este arquivo.
   ===================================================================== */

function consultarSefazDFe(array $cfg, string $ultNSU): array {
    $pfx = @file_get_contents(__DIR__ . '/certificado/' . $cfg['arquivo']);
    if ($pfx === false) {
        return ['ok' => false, 'erro' => 'Certificado não encontrado em sefaz/certificado/' . $cfg['arquivo'] . '. Faça o upload dele primeiro.'];
    }

    $certs = [];
    while (openssl_error_string()) {} // limpa erros antigos da fila
    $abriu = openssl_pkcs12_read($pfx, $certs, $cfg['senha']);
    if (!$abriu) {
        $detalhes = [];
        while ($e = openssl_error_string()) { $detalhes[] = $e; }
        $pista = '';
        foreach ($detalhes as $d) {
            if (stripos($d, 'mac verify failure') !== false) { $pista = ' Isso normalmente indica que a SENHA está incorreta.'; break; }
        }
        $resumoErro = $detalhes ? implode(' | ', $detalhes) : 'sem detalhe adicional do OpenSSL';
        return ['ok' => false, 'erro' => "Não consegui abrir o certificado.$pista Detalhe técnico: $resumoErro"];
    }

    // grava cert e chave em arquivos temporários só pelo tempo da chamada
    $arqCert = tempnam(sys_get_temp_dir(), 'sfz_cert_');
    $arqKey  = tempnam(sys_get_temp_dir(), 'sfz_key_');
    file_put_contents($arqCert, $certs['cert']);
    file_put_contents($arqKey, $certs['pkey']);
    chmod($arqCert, 0600);
    chmod($arqKey, 0600);

    $tpAmb = $cfg['ambiente'];
    $uf    = $cfg['uf'];
    $cnpj  = $cfg['cnpj'];

    $distDFeInt = '<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.35">'
        . "<tpAmb>$tpAmb</tpAmb>"
        . "<cUFAutor>$uf</cUFAutor>"
        . "<CNPJ>$cnpj</CNPJ>"
        . '<distNSU><ultNSU>' . str_pad($ultNSU, 15, '0', STR_PAD_LEFT) . '</ultNSU></distNSU>'
        . '</distDFeInt>';

    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
        . '<soap12:Body><nfeDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">'
        . '<nfeDadosMsg>' . $distDFeInt . '</nfeDadosMsg>'
        . '</nfeDistDFeInteresse></soap12:Body></soap12:Envelope>';

    $url = ($tpAmb == 2)
        ? 'https://hom.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx'
        : 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $envelope,
        CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml; charset=utf-8'],
        CURLOPT_SSLCERT => $arqCert,
        CURLOPT_SSLKEY  => $arqKey,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // apaga os arquivos temporários do certificado imediatamente
    @unlink($arqCert);
    @unlink($arqKey);

    if ($erroCurl) {
        return ['ok' => false, 'erro' => 'Falha de conexão com a Sefaz: ' . $erroCurl];
    }
    if ($httpCode !== 200) {
        return ['ok' => false, 'erro' => "Sefaz respondeu código $httpCode (esperado 200). Resposta: " . substr($resposta, 0, 300)];
    }

    return interpretarRespostaSefaz($resposta);
}

function interpretarRespostaSefaz(string $xmlResposta): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xmlResposta)) {
        return ['ok' => false, 'erro' => 'Resposta da Sefaz não é um XML válido.'];
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');

    $cStat = $xp->query('//n:cStat')->item(0);
    $xMotivo = $xp->query('//n:xMotivo')->item(0);
    if (!$cStat) {
        return ['ok' => false, 'erro' => 'Resposta da Sefaz em formato inesperado.', 'bruto' => substr($xmlResposta, 0, 500)];
    }

    $codigo = $cStat->textContent;
    $motivo = $xMotivo ? $xMotivo->textContent : '';

    // 137 = nenhum documento novo. Não é erro, é "está tudo em dia".
    if ($codigo === '137') {
        return ['ok' => true, 'semNovidade' => true, 'ultNSU' => textoNo($xp, '//n:ultNSU'), 'documentos' => []];
    }
    // 138 = documentos localizados, o caso bom.
    if ($codigo === '138') {
        $docs = [];
        foreach ($xp->query('//n:docZip') as $docZip) {
            $conteudo = gzdecode(base64_decode($docZip->textContent));
            $docs[] = ['schema' => $docZip->getAttribute('schema'), 'xml' => $conteudo];
        }
        return ['ok' => true, 'semNovidade' => false, 'ultNSU' => textoNo($xp, '//n:ultNSU'), 'maxNSU' => textoNo($xp, '//n:maxNSU'), 'documentos' => $docs];
    }
    // 656 = consumo indevido (fizemos consultas rápido demais / duplicadas). Não é bug, é limite de uso.
    if ($codigo === '656') {
        return ['ok' => false, 'erro' => "Sefaz bloqueou por consumo indevido. Texto oficial da Sefaz: \"$motivo\" (código 656). Isso costuma exigir esperar mais que 1h se houve várias tentativas seguidas antes.", 'codigo' => $codigo];
    }

    return ['ok' => false, 'erro' => "Sefaz retornou: [$codigo] $motivo", 'codigo' => $codigo];
}

function textoNo($xp, $caminho) {
    $n = $xp->query($caminho)->item(0);
    return $n ? $n->textContent : null;
}
