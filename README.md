# Foodservice360

Sistema de gestão para restaurantes (contagem de estoque, notas fiscais, CMV),
hospedado na HostGator, organizado por cliente.

## Estrutura

```
eb/                         Cliente: Espeto Brasileiro
  copa/                      Tela de contagem — setor Copa
  cozinha/                   Tela de contagem — setor Cozinha
  enviar.php                 Recebe a contagem e envia por email
  config.example.php         Modelo do config de email da contagem (copie para config.php)
  lib/                       PHPMailer (biblioteca de envio de email)
  notas/                     Módulo de notas fiscais
    upload.html/.php          Upload manual de XML (feito pelo Alex)
    validar.html               Tela da gerente: valida, confirma, lança compra avulsa
    adicionar.php               Recebe compra avulsa (digitada + foto)
    confirmar.php                Confirma nota pendente, gera planilha, envia
    excluir.php                   Marca nota como suspeita (nunca apaga de verdade)
    listar.php                    Lista notas pendentes de uma loja
    lojas.json                    Cadastro das lojas do grupo (id, nome, CNPJ)
    config.example.php            Modelo do config de email das notas (copie para config.php)
    lib/confirmar_nota.php        Lógica compartilhada: monta planilha + envia email
    lib/ler_nota.php              Lê e interpreta um XML de NFe
    sefaz/                        Busca automática de notas na Sefaz (Distribuição DFe)
      config.example.php          Modelo (CNPJ, UF, nome do certificado — copie para config.php)
      buscar.php                   Dispara a busca (rodar manual ou por Cron Job)
      cliente.php                  Fala com o webservice da Sefaz
      certificado/                 Pasta onde o .pfx fica (nunca vai pro Git)
```

## Primeira vez configurando um ambiente novo (produção ou staging)

Depois que o Git trouxer o código pra pasta, **três arquivos precisam ser
criados manualmente, direto no servidor** (nunca no Git, por segurança):

1. Copie `eb/config.example.php` para `eb/config.php` e cole a senha real
   do email da contagem.
2. Copie `eb/notas/config.example.php` para `eb/notas/config.php` e cole
   a senha real do email de notas.
3. Se for usar a busca automática da Sefaz: copie
   `eb/notas/sefaz/config.example.php` para `config.php`, preencha o CNPJ
   e a UF, e suba o arquivo `.pfx` do certificado na pasta `certificado/`.

Sem isso, o sistema roda normal, só o envio de email (ou a busca na Sefaz)
não funciona até esses arquivos existirem.

## Como atualizar o código (deploy)

Pelo cPanel, em **Git™ Version Control**, clique em **Manage** no
repositório e depois em **Update from Remote** (traz o que mudou no
GitHub) e **Deploy HEAD Commit** (aplica na pasta do site).
