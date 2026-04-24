# Vupi.us API

**Crie um backend PHP funcional em 5 minutos**

Sem instalar nada. Sem configurar servidor. Comece direto no navegador.

## 🚀 O que você consegue fazer

- Criar projeto PHP com 1 clique
- Criar tabelas e CRUD automático
- Autenticação JWT pronta
- Deploy automático com Git push
- IDE completa no navegador

## ⚡ Instalação (1 comando)

```bash
git clone https://github.com/adimael/api_vupi.us_php.git
cd api_vupi.us_php
sudo bash install.sh
```

Pronto. Servidor rodando em `http://localhost:3005`

## 🎯 Como usar

### 1. Criar projeto
```bash
php vupi make:module MeuProjeto
```

### 2. Criar tabela
```php
// Database/Migrations/001_criar_produtos.php
$db->exec("CREATE TABLE produtos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255),
    preco DECIMAL(10,2)
)");
```

### 3. Criar CRUD
```php
// Controllers/ProdutoController.php
$router->get('/api/produtos', [ProdutoController::class, 'listar']);
$router->post('/api/produtos', [ProdutoController::class, 'criar']);
```

### 4. Deploy
```bash
git push
```

Pronto. API funcionando.

## 📚 Documentação

- [Como criar módulos](DOC/Modulos.md)
- [Autenticação JWT](DOC/Autenticacao.md)
- [Arquitetura completa](DOC/Arquitetura.md)

## 🧪 Testes

```bash
php vendor/phpunit/phpunit/phpunit
```

✅ 897 testes passando

## 📞 Contato

**Fundador:** Adimael S.  
**Email:** adimael@vupi.us  
**GitHub:** github.com/adimael

## 📄 Licença

MIT — Vupi.us API © 2026
