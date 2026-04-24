# Introdução

## O que é a Vupi.us API?

**Crie um backend PHP funcional em 5 minutos no navegador.**

A Vupi.us API é uma plataforma que permite criar APIs REST em PHP sem instalar nada localmente. Você abre o navegador, cria um projeto, escreve código e faz deploy. Pronto.

---

## Para quem é?

- **Freelancers PHP** que perdem dias configurando ambiente
- **Desenvolvedores** que querem prototipar rápido
- **Estudantes** aprendendo backend sem complicação

---

## O que você consegue fazer?

### 1. Criar projeto (1 clique)
```bash
php vupi make:module MeuProjeto
```

### 2. Criar tabela
```php
CREATE TABLE produtos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255),
    preco DECIMAL(10,2)
)
```

### 3. Criar API
```php
$router->get('/api/produtos', [Controller::class, 'listar']);
$router->post('/api/produtos', [Controller::class, 'criar']);
```

### 4. Deploy
```bash
git push
```

**Tempo total: 5 minutos**

## O que vem pronto?

- **Autenticação JWT** - Login, logout, refresh token
- **CRUD de usuários** - Registro, perfil, senha
- **IDE no navegador** - Criar e editar código online
- **Banco de dados** - PostgreSQL ou MySQL
- **Deploy automático** - Git push e pronto

## Instalação

```bash
git clone https://github.com/adimael/api_vupi.us_php.git
cd api_vupi.us_php
sudo bash install.sh
```

Pronto. Servidor rodando em `http://localhost:3005`

## Próximos passos

- [Como criar módulos](Modulos.md)
- [Autenticação JWT](Autenticacao.md)
- [Arquitetura completa](Arquitetura.md)

## Contato

**Fundador:** Adimael  
**Email:** adimael@vupi.us  
**GitHub:** github.com/adimael

## Licença

MIT — Vupi.us API © 2026
