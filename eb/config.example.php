<?php
/* =====================================================================
   CONFIGURAÇÃO DO ENVIO DE EMAIL
   Edite apenas o que está entre aspas e salve. Só a senha é obrigatória.
   ===================================================================== */

// Caixa de email que envia (a que você criou).
$EMAIL_CONTA   = 'contagemeb@foodservice360.com.br';

// >>>>>>  COLE AQUI A SENHA DESSA CAIXA DE EMAIL  <<<<<<
$EMAIL_SENHA   = 'COLE_A_SENHA_AQUI';

// Para onde a planilha vai (pode ser a mesma caixa, como backup).
$EMAIL_DESTINO = 'contagemeb@foodservice360.com.br';

/* ---------------------------------------------------------------------
   Servidor de email. Na maioria dos cPanel 'localhost' já funciona.
   Se não enviar, troque por 'mail.foodservice360.com.br'.
   Porta padrão que costuma funcionar: 587 com 'tls'.
   Alternativas se falhar: 465 com 'ssl', ou 25 com '' (vazio).
--------------------------------------------------------------------- */
$SMTP_HOST      = 'localhost';
$SMTP_PORT      = 587;
$SMTP_SEGURANCA = 'tls';
