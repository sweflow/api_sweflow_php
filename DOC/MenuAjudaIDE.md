# 📖 Menu de Ajuda - Vupi.us IDE

## 🔒 Segurança

**O terminal abre automaticamente dentro do seu módulo.**

- ✅ Execute comandos diretamente
- ❌ Comandos `cd` para sair do módulo são bloqueados
- ✅ Você não pode acessar pastas fora do módulo

---

## 🎯 Comandos Rápidos de Módulos

### 🔧 Gerenciamento de Dependências

#### Instalar Dependências
```bash
composer install
```
Instala todas as dependências do módulo.

#### Adicionar Dependência
```bash
composer require nome/pacote
```
Adiciona nova dependência ao módulo.

#### Atualizar Dependências
```bash
composer update
```
Atualiza dependências para versões mais recentes.

#### Remover Dependência
```bash
composer remove nome/pacote
```
Remove dependência do módulo.

#### Listar Dependências
```bash
composer show
```
Lista todas as dependências instaladas.

---

### 🌍 Gerenciamento de Ambiente

#### Criar .env
```bash
cp .env.example .env
```
Cria arquivo de configuração a partir do template.

#### Editar .env
```bash
nano .env
```
Abre editor para configurar variáveis de ambiente.

#### Ver .env
```bash
cat .env
```
Exibe conteúdo do arquivo .env.

---

### 📁 Navegação

#### Listar Arquivos
```bash
ls -la
```
Lista todos os arquivos e pastas.

#### Ver Estrutura
```bash
tree -L 2
```
Exibe estrutura de diretórios (2 níveis).

---

### 🔍 Verificação

#### Verificar PHP
```bash
php -v
```
Exibe versão do PHP.

#### Verificar Composer
```bash
composer --version
```
Exibe versão do Composer.

#### Verificar Dependências
```bash
ls vendor/
```
Lista dependências instaladas.

---

### 🧹 Limpeza

#### Limpar Cache
```bash
composer clear-cache
```
Limpa cache do Composer.

#### Reinstalar Dependências
```bash
rm -rf vendor/ composer.lock
composer install
```
Remove e reinstala todas as dependências.

---

## 🚀 Fluxos Comuns

### Setup Inicial
```bash
# Execute diretamente (já está no módulo)
composer install
cp .env.example .env
nano .env
```

### Adicionar Biblioteca
```bash
# Execute diretamente
composer require nome/pacote
```

### Atualizar Módulo
```bash
# Execute diretamente
composer update
```

---

## 📚 Documentação Completa

- [Comandos de Módulos](ComandosModulos.md)
- [Isolamento de Módulos](../ISOLAMENTO_MODULOS.md)
- [Guia do Desenvolvedor](../GUIA_DESENVOLVEDOR_MODULO.md)

---

## 💡 Dicas Rápidas

1. **O terminal já está no módulo** - Execute comandos diretamente
2. **Nunca versione .env ou vendor/**
3. **Use .env.example como template**
4. **Comandos cd são bloqueados** - Por segurança

---

## 🆘 Ajuda Rápida

### Erro: "Class not found"
```bash
rm -rf vendor/ composer.lock
composer install
```

### Erro: ".env not found"
```bash
cp .env.example .env
```

### Erro: "Permission denied"
```bash
chmod -R 755 src/Modules/SeuModulo
```

---

**Pressione F1 ou digite `/help` para mais comandos**
