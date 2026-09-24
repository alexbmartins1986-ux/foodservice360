<?php
/* =====================================================================
   CONFIGURAÇÃO DO ENVIO DE EMAIL — MÓDULO DE NOTAS
   Separado do email da contagem de propósito.
   Edite apenas o que está entre aspas e salve. Só a senha é obrigatória.
   ===================================================================== */

// Caixa de email que envia (crie essa conta de email antes de usar).
$EMAIL_CONTA   = 'notaseb@foodservice360.com.br';

// >>>>>>  COLE AQUI A SENHA DESSA CAIXA DE EMAIL  <<<<<<
$EMAIL_SENHA   = 'COLE_A_SENHA_AQUI';

// Para onde a planilha da nota vai (pode ser a mesma caixa, como backup).
$EMAIL_DESTINO = 'notaseb@foodservice360.com.br';

/* Mesmas configurações de servidor que já funcionaram na contagem. */
$SMTP_HOST      = 'localhost';
$SMTP_PORT      = 587;
$SMTP_SEGURANCA = 'tls';
