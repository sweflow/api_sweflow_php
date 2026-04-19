# 🔧 Comandos de Módulos - Vupi.us IDE

## 📋 Visão Geral

A Vupi.us IDE permite que desenvolvedores executem comandos diretamente em seus projetos de módulos, incluindo gerenciamento de dependências e configuração de ambiente.

---

## 🔒 Segurança do Terminal

**IMPORTANTE**: O terminal da IDE abre automaticamente **dentro da pasta do módulo**.

Por questões de segurança:
- ✅ Você já está no diretório correto do módulo
- ❌ Comandos `cd` para sair do módulo são **bloqueados**
- ✅ Execute comandos diretamente sem navegar
- ✅ Você não pode acessar pastas fora do seu módulo

---

## 🚀 Comandos Disponíveis

### 1. Gerenciamento de Dependências

#### Instalar Dependências do Módulo

```bash
composer install
```

**Descrição**: Instala todas as dependências definidas no `composer.json` do módulo.

**Quando usar**:
- Primeira vez configurando o módulo
- Após clonar o repositório
- Após adicionar novas dependências

**Exemplo**:
```bash
# Execute diretamente (você já está no módulo)
composer install
```

**Resultado**:
- Cria pasta `vendor/` no módulo
- Cria arquivo `composer.lock`
- Instala todas as dependências

---

#### Adicionar Nova Dependência

```bash
composer require nome/pacote
```

**Descrição**: Adiciona uma nova dependência ao módulo.

**Exemplo**:
```bash
# Adicionar Guzzle HTTP Client
composer require guzzlehttp/guzzle

# Adicionar biblioteca OAuth2
composer require league/oauth2-google
```

**Resultado**:
- Atualiza `composer.json`
- Instala o pacote em `vendor/`
- Atualiza `composer.lock`

---

#### Atualizar Dependências

```bash
composer update
```

**Descrição**: Atualiza todas as dependências para as versões mais recentes permitidas.

**Exemplo**:
```bash
composer update
```

---

#### Remover Dependência

```bash
composer remove nome/pacote
```

**Descrição**: Remove uma dependência do módulo.

**Exemplo**:
```bash
composer remove guzzlehttp/guzzle
```

---

#### Ver Dependências Instaladas

```bash
composer show
```

**Descrição**: Lista todas as dependências instaladas no módulo.

**Exemplo**:
```bash
composer show
```

---

### 2. Gerenciamento de Ambiente (.env)

#### Copiar Template de .env

```bash
cp .env.example .env
```

**Descrição**: Cria seu arquivo `.env` a partir do template.

**Quando usar**:
- Primeira vez configurando o módulo
- Após clonar o repositório

**Exemplo**:
```bash
# No terminal da IDE
cp .env.example .env
```

---

#### Editar .env

```bash
nano .env
```

**Descrição**: Abre o editor para configurar variáveis de ambiente.

**Exemplo**:
```bash
nano .env
```

**Alternativa** (usando editor da IDE):
- Abra o arquivo `.env` diretamente na IDE
- Edite as variáveis necessárias
- Salve o arquivo

---

#### Ver Conteúdo do .env

```bash
cat .env
```

**Descrição**: Exibe o conteúdo do arquivo `.env`.

**Exemplo**:
```bash
cat .env
```

---

### 3. Comandos de Verificação

#### Verificar Estrutura do Módulo

```bash
ls -la
```

**Descrição**: Lista todos os arquivos e pastas do módulo.

**Exemplo**:
```bash
ls -la
```

---

#### Verificar Dependências Instaladas

```bash
ls vendor/
```

**Descrição**: Lista as dependências instaladas no módulo.

**Exemplo**:
```bash
ls vendor/
```

---

#### Verificar Versão do PHP

```bash
php -v
```

**Descrição**: Exibe a versão do PHP instalada.

---

#### Verificar Versão do Composer

```bash
composer --version
```

**Descrição**: Exibe a versão do Composer instalada.

---

### 4. Comandos de Limpeza

#### Limpar Cache do Composer

```bash
composer clear-cache
```

**Descrição**: Limpa o cache do Composer.

---

#### Remover Dependências

```bash
rm -rf vendor/
rm composer.lock
```

**Descrição**: Remove todas as dependências instaladas.

**Quando usar**:
- Problemas com dependências corrompidas
- Antes de reinstalar tudo

**Exemplo**:
```bash
rm -rf vendor/
rm composer.lock
composer install
```

---

## 📝 Fluxo de Trabalho Completo

### Setup Inicial do Módulo

```bash
# O terminal já está no módulo, execute diretamente:

# 1. Instale as dependências
composer install

# 2. Copie o template de .env
cp .env.example .env

# 3. Edite o .env com suas configurações
nano .env

# 4. Verifique a instalação
ls -la
composer show
```

---

### Adicionar Nova Dependência

```bash
# Execute diretamente (já está no módulo):

# 1. Adicione a dependência
composer require nome/pacote

# 2. Verifique a instalação
composer show | grep nome/pacote
```

---

### Atualizar Módulo Após Clone

```bash
# Execute diretamente (já está no módulo):

# 1. Instale dependências
composer install

# 2. Configure .env
cp .env.example .env
nano .env

# 3. Pronto para desenvolver!
```

---

## 🎯 Comandos Específicos por Módulo

### Módulo Authenticador

```bash
# Setup inicial (execute diretamente, já está no módulo)
composer install
cp .env.example .env

# Edite o .env com suas credenciais Google OAuth2
nano .env

# Verifique instalação
composer show | grep oauth2
```

**Variáveis obrigatórias no .env**:
```env
GOOGLE_CLIENT_ID=seu-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=seu-client-secret
GOOGLE_REDIRECT_URI=http://localhost:3005/api/auth/google/callback
JWT_SECRET=sua-chave-secreta-minimo-32-caracteres
```

---

## 🔍 Troubleshooting

### Erro: "composer: command not found"

**Solução**: Verifique se o Composer está instalado no sistema.

```bash
# Verificar instalação
which composer

# Se não estiver instalado, instale via:
# https://getcomposer.org/download/
```

---

### Erro: "Class not found"

**Solução**: Reinstale as dependências.

```bash
cd src/Modules/SeuModulo
rm -rf vendor/
rm composer.lock
composer install
```

---

### Erro: "Permission denied"

**Solução**: Verifique permissões do diretório.

```bash
# Dar permissões de escrita
chmod -R 755 src/Modules/SeuModulo
```

---

### Erro: ".env not found"

**Solução**: Crie o arquivo .env.

```bash
cd src/Modules/SeuModulo
cp .env.example .env
```

---

## 📚 Referências

- [Composer Documentation](https://getcomposer.org/doc/)
- [PHP dotenv](https://github.com/vlucas/phpdotenv)
- [ISOLAMENTO_MODULOS.md](../ISOLAMENTO_MODULOS.md)
- [GUIA_DESENVOLVEDOR_MODULO.md](../GUIA_DESENVOLVEDOR_MODULO.md)

---

## 💡 Dicas

1. **Sempre trabalhe no diretório do módulo**
   ```bash
   cd src/Modules/SeuModulo
   ```

2. **Nunca versione .env ou vendor/**
   - `.env` contém credenciais sensíveis
   - `vendor/` é gerado automaticamente

3. **Use .env.example como template**
   - Sempre mantenha atualizado
   - Documente todas as variáveis

4. **Verifique isolamento**
   ```bash
   # Vendor do módulo
   ls src/Modules/SeuModulo/vendor/
   
   # Vendor do sistema (diferente!)
   ls vendor/
   ```

---

**Versão**: 1.0.0  
**Data**: 2026-04-19  
**Atualizado**: Comandos disponíveis na Vupi.us IDE
