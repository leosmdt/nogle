-- Executado uma vez, na criação do volume do MySQL: banco separado para os testes automatizados.
CREATE DATABASE IF NOT EXISTS nogle_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON nogle_test.* TO 'nogle'@'%';
