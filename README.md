# Desafio prático Nogle — Gestão de clientes

CRUD de clientes em uma tela única, com cadastro e edição em modal, busca por nome e CPF, inativação, reativação e exclusão, importação e exportação CSV, layout responsivo e preenchimento de endereço pelo CEP, além dos principais indicadores da carteira no topo da tela. Como extra opcional, um assistente com IA rodando no próprio computador entende pedidos por texto, voz e foto.

## Executar

Backend em **PHP 8.3 puro** (sem framework), banco **MySQL 8.4** e interface em HTML, CSS e JavaScript sem build. O jeito mais simples de subir tudo é com Docker:

```bash
docker compose up --build
```

Abra <http://127.0.0.1:8000> (se a porta estiver ocupada, use `APP_PORT=8080 docker compose up --build`). Na primeira execução o MySQL leva uns 20 segundos para inicializar. A tabela é criada automaticamente a partir de [`database/schema.sql`](database/schema.sql), e 10 clientes fictícios de cidades diferentes ([`database/seed.sql`](database/seed.sql)) são carregados se o banco estiver vazio.

### Sem Docker para o PHP

Requer PHP 8.2 ou superior com as extensões `pdo_mysql` e `mbstring`. O banco pode continuar no Docker:

```bash
docker compose up -d db        # só o MySQL, exposto em 127.0.0.1:3307
php bin/serve.php --seed       # aplica o esquema, carrega os exemplos e abre http://127.0.0.1:8000
```

Para usar outro MySQL, defina `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD`; a porta do servidor pode ser alterada com `--port` ou `PORT`. **Não há dependências do Composer em produção**: o Composer só instala o PHPUnit.

Sem a IA instalada, o botão **Assistente** continua disponível com interpretação simples de comandos (“buscar Maria”, “mostrar inativos”, “exportar”).

## Testes

```bash
docker compose up -d db
composer install
composer test
```

São 70 testes PHPUnit, sem precisar da IA. Os da API chamam a aplicação diretamente (sem servidor HTTP) sobre um banco separado, `nogle_test`, esvaziado antes de cada teste. Cobrem cadastro, validações, CPF único (com mensagem específica quando o CPF é de um cliente inativo), edição, inativação, reativação e exclusão, filtros sem diferença de acento, importação CSV (inclusive que uma linha inválida não grava nada), exportação com proteção contra fórmulas, indicadores, confirmação obrigatória nas ações em massa, erros internos e banco fora do ar devolvidos como JSON, bloqueio de host externo e as regras que corrigem as respostas dos modelos de IA, com respostas simuladas.

## Funcionalidades

- Cadastro e edição com validação de campos obrigatórios, CPF, telefone e CEP.
- CPF único no banco, inclusive entre clientes inativos.
- Filtro imediato por nome e CPF, sem precisar clicar em Buscar. A busca por nome ignora acentos e diferença entre maiúsculas e minúsculas.
- Abas de ativos e inativos, com contagem total independente dos filtros.
- Inativação em um clique, com botão **Desfazer** por alguns segundos. Clientes inativos também podem ser reativados na sua aba ou excluídos definitivamente, com confirmação no próprio botão.
- **Salvar e novo** mantém o modal aberto para cadastros em sequência.
- Consulta ao [ViaCEP](https://viacep.com.br/) ao digitar um CEP válido; cidade, UF, rua e bairro são preenchidos quando disponíveis, e o foco avança para o próximo campo necessário. Se o serviço estiver indisponível, o endereço pode ser preenchido manualmente.
- Atalhos: `N` abre um novo cadastro, `/` foca a busca, `Ctrl+Enter` ou `Cmd+Enter` salva o formulário, e `Esc` fecha o modal.
- Importação CSV de até 500 clientes por arquivo. CPFs já cadastrados (ou repetidos no próprio arquivo) são ignorados, e todas as linhas são validadas antes de gravar: se uma estiver errada, nada é importado.
- Exportação CSV dos clientes exibidos pelos filtros atuais.
- **Indicadores** no topo da tela: clientes ativos, total, inativos com a taxa de inativação e novos cadastros do mês comparados ao mês anterior. Atualizam a cada alteração na lista.
- **Dashboard** (botão ao lado de Exportar): clientes ativos por estado e cidades com mais clientes, com detalhes ao passar o mouse e alternativa em tabela. Cidades digitadas com e sem acento são somadas.
- **Tour** guiado na primeira visita, que pode ser revisto pelo botão **Tour** no canto inferior direito.

### Importar CSV

Use um arquivo UTF-8, separado por ponto e vírgula ou vírgula, com este cabeçalho:

```csv
nome;cpf;telefone;cep;cidade;estado;endereco;bairro;numero;complemento
```

`complemento` pode ficar vazio. Para obter um modelo pronto, clique em **Exportar** quando não houver clientes cadastrados. O arquivo exportado inclui BOM UTF-8 para abrir corretamente em planilhas.

## Assistente com IA local (opcional)

> **Nota para a avaliação:** o assistente é um **esboço**, uma ideia de como usar IA dentro da aplicação de CRUD. Nos testes, ele roda com uma IA local (modelos abaixo), apenas para funcionar sem chaves nem custos. Num cenário real, a proposta é trocar esses modelos por uma API de IA externa. O CRUD de clientes funciona completo sem o assistente.

```bash
bin/setup_local_ai.sh   # baixa executores e modelos (cerca de 3,8 GB)
bin/start_local.sh      # inicia os modelos e o servidor (aceita --seed)
```

| Recurso | Modelo | Plataformas |
| --- | --- | --- |
| Pedidos em texto | [Qwen3 1.7B](https://huggingface.co/ggml-org/Qwen3-1.7B-GGUF) via [llama.cpp](https://github.com/ggml-org/llama.cpp) | Linux x64/ARM64, macOS Apple Silicon/Intel |
| Voz | [Whisper small](https://huggingface.co/ggerganov/whisper.cpp) via [whisper.cpp](https://github.com/ggml-org/whisper.cpp) | Linux x64/ARM64; no macOS usa o reconhecimento do navegador |
| Foto | [Qwen3-VL 2B](https://huggingface.co/ggml-org/Qwen3-VL-2B-Instruct-GGUF) via llama.cpp | as mesmas do texto |

Os scripts foram testados em Ubuntu x64. No macOS, eles baixam os binários oficiais correspondentes, mas não foram executados em um Mac. Em outros sistemas, como Windows, use apenas o Docker. O `bin/start_local.sh` roda o PHP na própria máquina (precisa do MySQL no ar, com `docker compose up -d db`), porque os modelos ficam fora do contêiner.

Os modelos de texto e voz ficam carregados enquanto o servidor roda (cerca de 1,6 GB de RAM). O de visão (cerca de 2,5 GB) é iniciado pelo PHP na primeira foto e desligado pelo `bin/start_local.sh` após 10 minutos sem uso.

### Pedidos por texto

Clique em **Assistente** e peça, por exemplo, “qual o telefone da Maria?”, “quem mora em Curitiba?”, “quantos clientes inativos?”, “cadastre Ana com CPF ... e CEP ...”, “edite o telefone de João” ou “inative Maria”. O assistente entende continuações: depois de “procure a Maria”, “e o endereço dela?” ou “inative ela” se referem à mesma cliente.

A IA só traduz o pedido em uma ação limitada às funções do aplicativo; o próprio aplicativo consulta o banco e monta a resposta. Cadastros e edições abrem o formulário para revisão antes de salvar, e inativação pede confirmação. Nenhum dado da tabela é enviado ao modelo de texto, apenas o pedido atual e o anterior.

### Pedidos por voz

Clique no microfone, fale e clique de novo para enviar (a gravação para sozinha em 15 segundos). O navegador converte o áudio para WAV, o Whisper o transcreve localmente e o Qwen interpreta o texto; a resposta aparece no chat junto com a transcrição. Os nomes dos clientes cadastrados entram no prompt do Whisper para acertar a grafia, e a busca tolera variações como Natalia/Nathalia ou Sousa/Souza. Sem o Whisper, o microfone usa o reconhecimento do navegador, que dependendo do navegador pode enviar o áudio a um serviço externo.

### Cadastro por foto

Clique no 📎 (ou cole uma imagem no campo de texto) e envie uma foto com os dados do cliente: ficha em papel, formulário, print de conversa ou anotação, inclusive manuscrita. O modelo de visão lê a imagem e o cadastro abre preenchido para revisão; nada é salvo sem confirmação. O CPF passa pela validação normal e o CEP completa o endereço oficial quando o ViaCEP está disponível. Em um notebook sem placa de vídeo, a leitura leva de 15 a 45 segundos.

### Medir o acerto da IA

Com a IA instalada:

```bash
php evals/eval_ai.php       # 60 pedidos em texto, incluindo continuações de conversa
php evals/eval_vision.php   # 4 imagens de exemplo em evals/fixtures, campo a campo
```

## Estrutura

```text
bin/                         comandos
├── serve.php                espera o MySQL, aplica o esquema e sobe o servidor
├── setup_local_ai.sh        baixa os executores e modelos da IA local
├── start_local.sh           sobe os modelos e o servidor juntos
└── ai_platform.sh           versões e caminhos da IA, usado pelos dois scripts acima
public/                      interface (HTML, CSS e JavaScript sem build)
├── index.php                front controller: toda requisição passa por aqui
├── app.js                   cadastro, lista, filtros, importação e exportação
├── dashboard.js             indicadores e janela de gráficos
└── assistant.js             assistente e tour guiado
src/                         backend (namespace Nogle)
├── App.php                  rotas da API, códigos de status e tratamento de erros
├── Database.php             conexão PDO, transações, esquema e carga de exemplo
├── Http/                    Request (limites de tipo e tamanho do corpo) e Response
├── Clients/                 consultas SQL, validação (CPF, telefone, CEP, UF) e CSV
└── Ai/                      modelos locais: interpretação dos pedidos, voz e leitura de fotos
database/
├── schema.sql               tabela de clientes, índices e restrições
└── seed.sql                 10 clientes fictícios para demonstração
tests/                       PHPUnit: Api/ (rotas sobre o MySQL) e Ai/ (regras da IA, sem os modelos)
evals/                       medição do acerto da IA local, com as imagens de exemplo
docker/                      criação do banco de testes no MySQL
```

### Decisões técnicas

- **PHP sem framework.** Para um CRUD de uma tela, roteamento, validação e acesso a dados cabem em poucas classes, legíveis de ponta a ponta. O servidor embutido (`php -S`) roda com 4 workers, para que uma leitura de foto pela IA não trave a tela.
- **SQL explícito com PDO e prepared statements** em [`ClientRepository`](src/Clients/ClientRepository.php). A collation `utf8mb4_0900_ai_ci` faz a busca ignorar acentos e maiúsculas no próprio MySQL, e o ranking de cidades usa funções de janela.
- **Regras no banco também:** CPF único e restrições `CHECK` de formato, além da validação no PHP e no navegador.
- **`App` recebe um `Request` e devolve um `Response`**, sem tocar em variáveis globais: os testes exercitam as rotas reais sem subir um servidor.

## API

| Método | Rota | Função |
| --- | --- | --- |
| `GET` | `/api/clientes?status=ativos&nome=&cpf=&cidade=` | Listar e filtrar |
| `POST` | `/api/clientes` | Cadastrar |
| `PUT` | `/api/clientes/{id}` | Editar cliente ativo |
| `PATCH` | `/api/clientes/{id}/inativar` | Inativar |
| `PATCH` | `/api/clientes/{id}/reativar` | Reativar |
| `PATCH` | `/api/clientes/inativar` · `/api/clientes/reativar` | Inativar ou reativar todos (usado pelo assistente); exige o corpo `{"confirmar": true}` |
| `DELETE` | `/api/clientes/{id}` | Excluir definitivamente (só clientes inativos) |
| `GET` | `/api/clientes/export?status=ativos&nome=&cpf=` | Exportar CSV filtrado |
| `POST` | `/api/clientes/import` | Importar CSV |
| `GET` | `/api/dashboard` | Indicadores e distribuição geográfica |
| `GET` | `/api/assistant/status` | Estado da IA local |
| `POST` | `/api/assistant` | Interpretar um pedido (`message` e, opcionalmente, o pedido anterior em `context`) |
| `POST` | `/api/assistant/audio?context=` | Transcrever um WAV e interpretar o pedido |
| `POST` | `/api/assistant/image` | Ler uma foto (JPEG, PNG ou WebP) e devolver os campos do cadastro |

## Entrega

O código e os scripts de criação das tabelas, em [`database/`](database/), estão neste repositório. O vídeo de demonstração foi enviado separadamente.
