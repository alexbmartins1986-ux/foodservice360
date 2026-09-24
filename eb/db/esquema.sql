-- =====================================================================
-- ESQUEMA DO BANCO DE DADOS — CATÁLOGO DE PRODUTOS E VÍNCULO DO CMV
-- Não precisa editar nada aqui. Basta rodar o migrar.php uma vez.
-- =====================================================================

-- Cliente: hoje só o Espeto Brasileiro, mas já nasce pronto pra outros.
CREATE TABLE IF NOT EXISTS clientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lojas de cada cliente (o que hoje vive em lojas.json).
CREATE TABLE IF NOT EXISTS lojas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  codigo VARCHAR(50) NOT NULL COMMENT 'o "id" curto, tipo loja1',
  nome VARCHAR(150) NOT NULL,
  cnpj VARCHAR(20) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  UNIQUE KEY uq_loja_codigo (cliente_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fornecedores conhecidos (aprendido conforme as notas chegam).
CREATE TABLE IF NOT EXISTS fornecedores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  nome VARCHAR(200) NOT NULL,
  cnpj VARCHAR(20) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- O CATÁLOGO em si: o "produto oficial" do CMV, nome e unidade padronizados.
CREATE TABLE IF NOT EXISTS produtos_cmv (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  nome_padrao VARCHAR(200) NOT NULL,
  unidade_padrao VARCHAR(10) NOT NULL,
  custo_atual DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'média das últimas compras, recalculado a cada compra vinculada',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Apelidos aprendidos: qualquer texto (de contagem ou de fornecedor) que já
-- foi vinculado uma vez a um produto do catálogo. Da 2ª vez, reconhece sozinho.
CREATE TABLE IF NOT EXISTS produtos_apelidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  produto_cmv_id INT NOT NULL,
  apelido VARCHAR(200) NOT NULL,
  fornecedor_id INT NULL COMMENT 'preenchido quando o apelido veio de uma nota, nulo se veio da contagem',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (produto_cmv_id) REFERENCES produtos_cmv(id),
  FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
  UNIQUE KEY uq_apelido (produto_cmv_id, apelido, fornecedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Uma contagem enviada (copa OU cozinha), aguardando ou já vinculada.
CREATE TABLE IF NOT EXISTS contagens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_id INT NOT NULL,
  setor VARCHAR(30) NOT NULL COMMENT 'copa, cozinha, etc',
  responsavel VARCHAR(150) NULL,
  enviada_em DATETIME NOT NULL,
  vinculada TINYINT(1) NOT NULL DEFAULT 0,
  vinculada_em DATETIME NULL,
  FOREIGN KEY (loja_id) REFERENCES lojas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada item de uma contagem. produto_cmv_id fica NULO até alguém vincular.
CREATE TABLE IF NOT EXISTS contagens_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contagem_id INT NOT NULL,
  produto_cmv_id INT NULL,
  produto_texto_original VARCHAR(200) NOT NULL,
  unidade_original VARCHAR(10) NOT NULL,
  quantidade DECIMAL(12,3) NOT NULL,
  FOREIGN KEY (contagem_id) REFERENCES contagens(id),
  FOREIGN KEY (produto_cmv_id) REFERENCES produtos_cmv(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada item de compra (vindo de nota XML, Sefaz ou avulsa), aguardando
-- ou já vinculado a um produto do catálogo. Isso também é o histórico
-- de preços e fornecedores por produto, pra consulta futura.
CREATE TABLE IF NOT EXISTS compras_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loja_id INT NOT NULL,
  produto_cmv_id INT NULL,
  fornecedor_id INT NULL,
  nota_referencia VARCHAR(80) NULL COMMENT 'numero ou chave da nota de origem',
  produto_texto_original VARCHAR(200) NOT NULL,
  unidade_original VARCHAR(10) NOT NULL,
  quantidade DECIMAL(12,3) NOT NULL,
  valor_unitario DECIMAL(12,4) NOT NULL,
  valor_total DECIMAL(12,2) NOT NULL,
  data_compra DATE NOT NULL,
  vinculado TINYINT(1) NOT NULL DEFAULT 0,
  vinculado_em DATETIME NULL,
  FOREIGN KEY (loja_id) REFERENCES lojas(id),
  FOREIGN KEY (produto_cmv_id) REFERENCES produtos_cmv(id),
  FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
