# Documentação Vupi.us API

**Crie um backend PHP funcional em 5 minutos no navegador.**

---

## 🚀 Início Rápido

### 1. Instalar
```bash
git clone https://github.com/adimael/api_vupi.us_php.git
cd api_vupi.us_php
sudo bash install.sh
```

### 2. Criar projeto
```bash
php vupi make:module MeuProjeto
```

### 3. Criar tabela
```php
CREATE TABLE produtos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255),
    preco DECIMAL(10,2)
)
```

### 4. Criar API
```php
$router->get('/api/produtos', [Controller::class, 'listar']);
```

### 5. Deploy
```bash
git push
```

**Pronto. API funcionando.**

## 📚 Documentação

- **[Introdução](Introducao.md)** - O que é e como funciona
- **[Módulos](Modulos.md)** - Como criar módulos
- **[Autenticação](Autenticacao.md)** - JWT e OAuth2
- **[Arquitetura](Arquitetura.md)** - Detalhes técnicos
- **[Middlewares](Middlewares.md)** - Middlewares customizados


## 📞 Contato

**Fundador:** Adimael 
**Email:** adimael@vupi.us  
**GitHub:** github.com/adimael

## 📄 Licença

MIT — Vupi.us API © 2026
