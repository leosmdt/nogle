-- Clientes fictícios para demonstração (CPFs gerados com dígitos verificadores válidos).
-- Carregados por: php bin/serve.php --seed (somente quando o banco está vazio).

INSERT INTO clientes (nome, cpf, telefone, cep, cidade, estado, endereco, bairro, numero, complemento) VALUES
    ('Mariana Albuquerque Lopes', '15881399803', '11987452310', '01310100', 'São Paulo', 'SP', 'Avenida Paulista', 'Bela Vista', '1842', 'Apto 121'),
    ('Rafael Monteiro Siqueira', '69879730917', '21996314578', '22021001', 'Rio de Janeiro', 'RJ', 'Avenida Atlântica', 'Copacabana', '2650', 'Bloco B'),
    ('Juliana Ferreira Campos', '11417075350', '31988217465', '30130002', 'Belo Horizonte', 'MG', 'Avenida Afonso Pena', 'Centro', '1500', ''),
    ('Thiago Kowalski Ribas', '64556815177', '4133224187', '80020310', 'Curitiba', 'PR', 'Rua XV de Novembro', 'Centro', '731', 'Sala 4'),
    ('Camila Becker Rossi', '88447294072', '51984560239', '90020025', 'Porto Alegre', 'RS', 'Avenida Borges de Medeiros', 'Centro Histórico', '410', ''),
    ('Lucas Andrade Nascimento', '55761698757', '71991827364', '40060001', 'Salvador', 'BA', 'Avenida Sete de Setembro', 'Centro', '988', 'Casa 2'),
    ('Beatriz Cavalcanti Melo', '16897684600', '81997703152', '51011000', 'Recife', 'PE', 'Avenida Boa Viagem', 'Boa Viagem', '3270', 'Apto 802'),
    ('Pedro Henrique Sales Rocha', '97880932054', '85988641029', '60165121', 'Fortaleza', 'CE', 'Avenida Beira Mar', 'Meireles', '2120', ''),
    ('Larissa Moura Teixeira', '81967273138', '61999152847', '70355000', 'Brasília', 'DF', 'SQS 308 Bloco C', 'Asa Sul', '305', 'Apto 305'),
    ('Gabriel Souza Bentes', '77848648842', '92991346805', '69010001', 'Manaus', 'AM', 'Avenida Eduardo Ribeiro', 'Centro', '620', '');
