-- Tabela de clientes (MySQL 8.4). Aplicado automaticamente por bin/serve.php e pelos testes.
-- A collation utf8mb4_0900_ai_ci ignora acentos e maiúsculas: a busca por "jose" encontra "José".

CREATE TABLE IF NOT EXISTS clientes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(180) NOT NULL,
    cpf CHAR(11) NOT NULL,
    telefone VARCHAR(11) NOT NULL,
    cep CHAR(8) NOT NULL,
    cidade VARCHAR(180) NOT NULL,
    estado CHAR(2) NOT NULL,
    endereco VARCHAR(180) NOT NULL,
    bairro VARCHAR(180) NOT NULL,
    numero VARCHAR(180) NOT NULL,
    complemento VARCHAR(180) NOT NULL DEFAULT '',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_cpf (cpf),
    -- Atende o filtro por status com a ordenação por nome da listagem.
    KEY idx_clientes_ativo_nome (ativo, nome),
    CONSTRAINT chk_clientes_cpf CHECK (cpf REGEXP '^[0-9]{11}$'),
    CONSTRAINT chk_clientes_telefone CHECK (telefone REGEXP '^[0-9]{10,11}$'),
    CONSTRAINT chk_clientes_cep CHECK (cep REGEXP '^[0-9]{8}$'),
    CONSTRAINT chk_clientes_estado CHECK (estado REGEXP '^[A-Z]{2}$'),
    CONSTRAINT chk_clientes_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
