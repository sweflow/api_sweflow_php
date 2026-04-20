# Configuração de Banco de Dados Personalizado na IDE

## Visão Geral

A IDE da Vupi.us permite que desenvolvedores configurem uma conexão de banco de dados personalizada para seus módulos. Isso garante que:

- ✅ Seus módulos usam um banco de dados isolado (ex: PostgreSQL no Aiven, MySQL local, etc.)
- ✅ Módulos nativos (Auth, Usuario, etc.) continuam usando o banco core da aplicação
- ✅ Migrations e tabelas são criadas no banco correto automaticamente
- ✅ Não há necessidade de configurar manualmente cada módulo

---

## Por que Usar Banco de Dados Personalizado?

### Isolamento de Dados
- Seus módulos não compartilham o mesmo banco que o sistema core
- Dados de desenvolvimento ficam separados dos dados de produção
- Facilita backup e restore de dados específicos

### Flexibilidade
- Use PostgreSQL enquanto o core usa MySQL (ou vice-versa)
- Conecte-se a bancos em nuvem (Aiven, AWS RDS, Google Cloud SQL, etc.)
- Teste diferentes configurações sem afetar o sistema principal

### Segurança
- Credenciais são criptografadas com AES-256-CBC
- Conexões SSL/TLS suportadas (require, verify-ca, verify-full)
- Certificados CA podem ser configurados para conexões seguras

---

## Como Configurar

### 1. Acessar a Página de Projetos

Navegue até `/dashboard/ide` na sua aplicação.

### 2. Localizar o Card de Banco de Dados

Na página de projetos, você verá um card chamado **"Conexão de Banco de Dados"** com as seguintes informações:

- **Status**: Ativa ou Inativa
- **Migrations Pendentes**: Número de migrations que ainda não foram executadas
- **Tabelas**: Número de tabelas criadas no banco personalizado

### 3. Configurar Nova Conexão

Clique no botão **"Configurar Banco de Dados"** e preencha os campos:

#### Campos Obrigatórios

| Campo | Descrição | Exemplo |
|-------|-----------|---------|
| **Nome da conexão** | Nome amigável para identificar a conexão | "Meu PostgreSQL Local" |
| **Driver** | Tipo de banco de dados | PostgreSQL ou MySQL |
| **Nome do banco** | Nome do database | `meu_banco_dev` |
| **Host** | Endereço do servidor | `localhost` ou `pg-xxx.aivencloud.com` |
| **Porta** | Porta do banco de dados | `5432` (PostgreSQL) ou `3306` (MySQL) |
| **Usuário** | Nome de usuário do banco | `postgres` ou `root` |
| **Senha** | Senha do banco de dados | `••••••••` |

#### Campos Opcionais (SSL/TLS)

| Campo | Descrição | Valores |
|-------|-----------|---------|
| **Modo SSL** | Tipo de conexão SSL | Nenhum, Require, Verify CA, Verify Full |
| **Certificado CA** | Certificado para verificação SSL | Conteúdo do arquivo `.pem` |

### 4. Testar Conexão

Antes de salvar, clique em **"Testar Conexão"** para verificar se:

- ✅ O servidor está acessível
- ✅ As credenciais estão corretas
- ✅ O banco de dados existe (ou pode ser criado)
- ✅ A conexão SSL está funcionando (se configurada)

### 5. Salvar e Ativar

Clique em **"Conectar"** para salvar a configuração. A conexão será ativada automaticamente e todos os seus módulos passarão a usar este banco de dados.

---

## Exemplos de Configuração

### PostgreSQL Local

```
Nome da conexão: PostgreSQL Local
Driver: PostgreSQL
Nome do banco: vupi_dev
Host: localhost
Porta: 5432
Usuário: postgres
Senha: sua_senha
Modo SSL: Nenhum
```

### PostgreSQL no Aiven (Cloud)

```
Nome da conexão: Aiven PostgreSQL
Driver: PostgreSQL
Nome do banco: defaultdb
Host: pg-xxx-yyy.aivencloud.com
Porta: 12345
Usuário: avnadmin
Senha: sua_senha_aiven
Modo SSL: Require
Certificado CA: (cole o conteúdo do ca.pem fornecido pelo Aiven)
```

### MySQL Local

```
Nome da conexão: MySQL Local
Driver: MySQL
Nome do banco: vupi_dev
Host: localhost
Porta: 3306
Usuário: root
Senha: sua_senha
Modo SSL: Nenhum
```

---

## Gerenciamento da Conexão

### Visualizar Detalhes

No card de banco de dados, você pode:

- **Ver/Ocultar dados**: Clique no ícone de olho para mostrar/ocultar host, porta e nome do banco
- **Ver migrations pendentes**: Número de migrations que ainda não foram executadas
- **Ver tabelas**: Lista de todas as tabelas criadas no banco personalizado

### Editar Conexão

1. Clique no botão **"Editar"** no card
2. Modifique os campos desejados
3. **Nota**: Se não preencher a senha, a senha atual será mantida
4. Clique em **"Testar Conexão"** para validar
5. Clique em **"Conectar"** para salvar

### Excluir Conexão

1. Clique no botão **"Excluir"** no card
2. Confirme a exclusão no modal
3. **Importante**: Seus módulos voltarão a usar o banco de dados padrão da Vupi.us API
4. **Nota**: As tabelas e dados no banco personalizado **não serão afetados**

---

## Comportamento do Sistema

### Módulos Nativos vs Desenvolvedor

O sistema distingue automaticamente entre módulos nativos e módulos do desenvolvedor:

| Tipo | Módulos | Banco Usado |
|------|---------|-------------|
| **Nativos** | Auth, Usuario, Authenticador, Documentacao, IdeModuleBuilder, System | Banco Core (DB_*) |
| **Desenvolvedor** | Todos os outros módulos criados por você | Banco Personalizado (se configurado) |

### Conexões Persistentes

O sistema usa **conexões persistentes** (PDO::ATTR_PERSISTENT) para melhorar o desempenho:

- **Primeira requisição**: ~3500ms (estabelece conexão TCP+SSL)
- **Requisições subsequentes**: ~50-200ms (reutiliza conexão)
- **Melhoria**: 95% mais rápido após a primeira requisição

### Cache por Requisição

Para evitar múltiplas resoluções de conexão na mesma requisição, o sistema mantém um cache em memória que é descartado ao final de cada request.

---

## Migrations e Seeders

### Execução Automática

Quando você cria um novo módulo na IDE:

1. As migrations são detectadas automaticamente
2. Elas são executadas no banco de dados personalizado (se configurado)
3. As tabelas são criadas no banco correto
4. O contador de "Migrations Pendentes" é atualizado

### Verificação de Migrations

Na página de projetos, o card mostra:

- **Migrations Pendentes**: Número de migrations que ainda não foram executadas
- **Tabelas**: Lista de tabelas criadas no banco personalizado

### Executar Migrations Manualmente

Se necessário, você pode executar migrations via CLI:

```bash
php vupi migrate
```

---

## Segurança

### Criptografia de Senhas

Todas as senhas são criptografadas antes de serem armazenadas no banco de dados:

- **Algoritmo**: AES-256-CBC
- **Chave**: `APP_KEY` ou `JWT_SECRET` do `.env`
- **IV**: Gerado aleatoriamente para cada senha
- **Armazenamento**: Base64 (IV + dados criptografados)

### Conexões SSL/TLS

Para conexões em produção ou com bancos em nuvem, é **altamente recomendado** usar SSL:

#### Modo Require
- Força conexão SSL/TLS
- Não verifica o certificado do servidor
- **Uso**: Desenvolvimento ou quando você confia na rede

#### Modo Verify CA
- Força conexão SSL/TLS
- Verifica o certificado do servidor contra o CA fornecido
- **Uso**: Produção com certificado CA conhecido

#### Modo Verify Full
- Força conexão SSL/TLS
- Verifica o certificado do servidor contra o CA fornecido
- Verifica se o hostname do certificado corresponde ao host da conexão
- **Uso**: Produção com máxima segurança

### Certificados CA

Para usar Verify CA ou Verify Full, você precisa fornecer o certificado CA:

1. Obtenha o arquivo `.pem` do seu provedor (Aiven, AWS RDS, etc.)
2. Abra o arquivo em um editor de texto
3. Copie todo o conteúdo (incluindo `-----BEGIN CERTIFICATE-----` e `-----END CERTIFICATE-----`)
4. Cole no campo "Certificado CA" no modal de configuração

---

## Troubleshooting

### Erro: "Falha ao conectar"

**Possíveis causas**:
- Host ou porta incorretos
- Firewall bloqueando a conexão
- Credenciais inválidas
- Banco de dados não existe

**Solução**:
1. Verifique se o servidor está acessível: `ping host`
2. Verifique se a porta está aberta: `telnet host porta`
3. Confirme as credenciais no painel do provedor
4. Crie o banco de dados manualmente se necessário

### Erro: "SSL connection required"

**Causa**: O servidor exige conexão SSL mas você configurou "Nenhum"

**Solução**:
1. Altere o "Modo SSL" para "Require" ou superior
2. Se usar Verify CA/Full, adicione o certificado CA
3. Teste a conexão novamente

### Erro: "Certificate verification failed"

**Causa**: O certificado CA fornecido não corresponde ao certificado do servidor

**Solução**:
1. Obtenha o certificado CA correto do seu provedor
2. Certifique-se de copiar o conteúdo completo do arquivo
3. Verifique se não há espaços extras ou quebras de linha incorretas

### Migrations não são executadas

**Causa**: A conexão não está ativa ou há erro nas migrations

**Solução**:
1. Verifique se a conexão está com status "Ativa"
2. Verifique os logs do servidor para erros de SQL
3. Execute `php vupi migrate` manualmente para ver o erro detalhado

### Módulo usa banco errado

**Causa**: O módulo pode estar na lista de módulos nativos

**Solução**:
1. Verifique se o nome do módulo não está na lista de nativos
2. Módulos nativos sempre usam o banco core
3. Renomeie o módulo se necessário

---

## Boas Práticas

### Desenvolvimento

- ✅ Use banco de dados local para desenvolvimento
- ✅ Configure SSL apenas em produção (para melhor performance local)
- ✅ Mantenha backups regulares do banco personalizado
- ✅ Teste a conexão antes de criar muitos módulos

### Produção

- ✅ **Sempre** use SSL/TLS (Require ou superior)
- ✅ Use senhas fortes (mínimo 16 caracteres)
- ✅ Configure Verify Full para máxima segurança
- ✅ Use bancos gerenciados em nuvem (Aiven, AWS RDS, etc.)
- ✅ Monitore o uso de conexões persistentes

### Segurança

- ✅ Nunca compartilhe credenciais de banco de dados
- ✅ Use variáveis de ambiente para senhas sensíveis
- ✅ Rotacione senhas periodicamente
- ✅ Limite acesso ao banco apenas aos IPs necessários
- ✅ Mantenha o certificado CA atualizado

---

## Perguntas Frequentes

### Posso ter múltiplas conexões?

Atualmente, o sistema suporta **uma conexão ativa por usuário**. Se você configurar uma nova conexão, ela substituirá a anterior.

### O que acontece se eu excluir a conexão?

Seus módulos voltarão a usar o banco de dados padrão da Vupi.us API (configurado em `DB_*` no `.env`). As tabelas e dados no banco personalizado **não serão afetados**.

### Posso usar o mesmo banco para múltiplos usuários?

Sim, mas cada usuário precisa configurar sua própria conexão. As credenciais são armazenadas de forma isolada por usuário.

### Como faço backup do banco personalizado?

Use as ferramentas nativas do banco de dados:

**PostgreSQL**:
```bash
pg_dump -h host -p porta -U usuario -d banco > backup.sql
```

**MySQL**:
```bash
mysqldump -h host -P porta -u usuario -p banco > backup.sql
```

### Posso mudar de PostgreSQL para MySQL?

Sim, mas você precisará:
1. Exportar os dados do banco atual
2. Criar uma nova conexão com o novo driver
3. Adaptar as migrations se necessário (sintaxe SQL pode variar)
4. Importar os dados no novo banco

---

## Referências

- [Documentação de Módulos](Modulos.md)
- [Arquitetura do Sistema](Arquitetura.md)
- [Segurança](../SEGURANCA.md)
- [Aiven PostgreSQL](https://aiven.io/postgresql)
- [AWS RDS](https://aws.amazon.com/rds/)
- [Google Cloud SQL](https://cloud.google.com/sql)

---

**Última atualização**: 2026-04-20
