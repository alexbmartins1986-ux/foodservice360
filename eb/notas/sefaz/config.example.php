<?php
/* =====================================================================
   CONFIGURAÇÃO DA BUSCA AUTOMÁTICA NA SEFAZ
   Edite apenas o que está entre aspas.
   ===================================================================== */

// CNPJ da empresa dona do certificado (só números, sem ponto nem traço).
$SEFAZ_CNPJ = '00000000000000';

// Código da UF da empresa (RJ = 33, SP = 35, MG = 31 etc.)
// Lista completa: https://www.oobj.com.br/bc/article/quais-c%C3%B3digos-de-uf-utilizados-em-nf-e-90.html
$SEFAZ_UF = 33;

// Nome do arquivo do certificado (.pfx), que deve estar em sefaz/certificado/
// dentro desta mesma pasta. NUNCA compartilhe este arquivo fora daqui.
$SEFAZ_CERT_ARQUIVO = 'certificado.pfx';

// >>>>>>  COLE AQUI A SENHA DO CERTIFICADO  <<<<<<
$SEFAZ_CERT_SENHA = 'COLE_A_SENHA_DO_CERTIFICADO_AQUI';

// Ambiente: 1 = produção (notas de verdade), 2 = homologação (teste).
// Comece com 2 se quiser testar sem misturar com o certificado de produção.
$SEFAZ_AMBIENTE = 1;

// Qual loja (do lojas.json) essas notas pertencem, já que o certificado
// é de UM CNPJ específico. Se cada loja tiver seu próprio certificado,
// crie uma pasta sefaz-<loja> separada para cada uma.
$SEFAZ_LOJA_DESTINO = 'loja1';
