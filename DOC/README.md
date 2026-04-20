# Documentação Vupi.us API

Bem-vindo à documentação completa da Vupi.us API — uma plataforma modular de API RESTful construída em PHP 8.2.

---

## 📖 Índice

### Primeiros Passos

- **[Introdução](Introducao.md)** - Visão geral, arquitetura e propósito da plataforma
- **Instalação e Configuração** *(em breve)* - Setup completo, variáveis de ambiente e Docker

### Desenvolvimento

- **[Módulos](Modulos.md)** - Como criar, instalar e gerenciar módulos
- **[Autenticação](Autenticacao.md)** - Sistema plugável, contratos, JWT, OAuth2, LDAP
- **[Middlewares](Middlewares.md)** - Como criar e usar middlewares customizados
- **[Arquitetura](Arquitetura.md)** - Detalhes técnicos da arquitetura do sistema

### IDE (Ambiente de Desenvolvimento Integrado)

- **[Menu de Ajuda da IDE](MenuAjudaIDE.md)** - Comandos rápidos e dicas para usar a IDE
- **[Configuração de Banco de Dados Personalizado](ConfiguracaoBancoDadosIDE.md)** - Como configurar banco de dados isolado para desenvolvimento
- **[Comandos de Módulos](ComandosModulos.md)** - Referência de comandos para gerenciar módulos

### Segurança e Operações

- **Segurança** *(em breve)* - Rate limiting, circuit breaker, audit logging
- **Deploy** *(em breve)* - Caddy, PM2, Nginx e arquitetura de produção
- **CLI** *(em breve)* - Referência de todos os comandos `php vupi`

### Changelog e Bugfixes

- **[Changelog da Documentação](CHANGELOG_DOCUMENTACAO.md)** - Histórico de alterações na documentação
- **[Bugfix: Documentação CSS](BUGFIX_DOCUMENTACAO_CSS.md)** - Correção de problemas de CSS
- **[Bugfix: Dependência Circular](BUGFIX_DEPENDENCIA_CIRCULAR.md)** - Resolução de dependências circulares

---

## 🚀 Início Rápido

### 1. Instalação

```bash
git clone https://github.com/seu-repo/api_vupi.us_php.git
cd api_vupi.us_php
sudo bash install.sh
```

### 2. Criar Primeiro Módulo

Via CLI:
```bash
php vupi make:module MeuModulo
```

Ou via IDE:
1. Acesse `/dashboard/ide`
2. Clique em "Novo Projeto"
3. Preencha o nome e descrição
4. Clique em "Criar Projeto"

### 3. Configurar Banco de Dados Personalizado

1. Acesse `/dashboard/ide`
2. No card "Conexão de Banco de Dados", clique em "Configurar"
3. Preencha as credenciais do seu banco
4. Teste a conexão
5. Clique em "Conectar"

Veja mais detalhes em [Configuração de Banco de Dados Personalizado](ConfiguracaoBancoDadosIDE.md).

---

## 🎯 Recursos Principais

### Arquitetura Modular
- Sistema de módulos dinâmicos
- Carregamento automático
- Isolamento de dependências
- Suporte a múltiplos bancos de dados

### Autenticação Plugável
- JWT nativo com refresh tokens
- Suporte a OAuth2, LDAP, SAML
- Contratos para customização total
- Múltiplos módulos de auth simultâneos

### IDE Integrada
- Editor de código com syntax highlighting
- Terminal integrado com segurança
- Gerenciamento de dependências
- Configuração de banco de dados personalizado
- Execução de migrations e seeders

### Segurança em Múltiplas Camadas
- Rate limiting por IP e usuário
- Circuit breaker para proteção de banco
- Threat scoring baseado em comportamento
- Audit logging de eventos críticos
- Headers de segurança (CSP, HSTS, etc.)
- Validação de segredos em produção

---

## 📦 Módulos Nativos

| Módulo | Descrição |
|--------|-----------|
| **Auth** | Autenticação JWT completa |
| **Usuario** | Gerenciamento de usuários |
| **IdeModuleBuilder** | IDE integrada |
| **LinkEncurtador** | Encurtador de URLs |
| **Documentacao** | Documentação integrada |

---

## 🛠️ Stack Tecnológico

- **Linguagem**: PHP 8.2
- **Banco de Dados**: PostgreSQL 15 / MySQL 8.0
- **Autenticação**: JWT via `firebase/php-jwt`
- **Proxy Reverso**: Caddy (HTTPS automático)
- **Containerização**: Docker + Docker Compose
- **Testes**: PHPUnit
- **Análise Estática**: PHPStan (nível 6)

---

## 📝 Convenções

### Nomenclatura de Módulos
- **PascalCase**: `GestaoFinanceira`, `BlogPost`, `Crm`
- **Máximo**: 64 caracteres
- **Apenas**: Letras e números

### Estrutura de Pastas
```
src/Modules/MeuModulo/
├── Controllers/
├── Services/
├── Repositories/
├── Entities/
├── Middlewares/
├── Exceptions/
├── Database/
│   ├── Migrations/
│   ├── Seeders/
│   └── connection.php
└── Routes/
    └── web.php
```

### Namespaces
```php
namespace Src\Modules\MeuModulo\Controllers;
namespace Src\Modules\MeuModulo\Services;
namespace Src\Modules\MeuModulo\Repositories;
```

---

## 🤝 Contribuindo

Contribuições são bem-vindas! Por favor:

1. Fork o repositório
2. Crie uma branch para sua feature (`git checkout -b feature/MinhaFeature`)
3. Commit suas mudanças (`git commit -m 'feat: adiciona MinhaFeature'`)
4. Push para a branch (`git push origin feature/MinhaFeature`)
5. Abra um Pull Request

---

## 📄 Licença

MIT — Vupi.us API © 2026

---

## 🆘 Suporte

- **Documentação**: [DOC/](.)
- **Issues**: [GitHub Issues](https://github.com/seu-repo/api_vupi.us_php/issues)
- **Discussões**: [GitHub Discussions](https://github.com/seu-repo/api_vupi.us_php/discussions)

---

**Última atualização**: 2026-04-20
