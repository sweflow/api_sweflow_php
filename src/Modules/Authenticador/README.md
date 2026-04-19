# 🔐 Módulo Authenticador

Módulo de Autenticação e Autorização com suporte a OAuth2, JWT, 2FA e Rate Limiting.

---

## 🏗️ Isolamento do Módulo

**IMPORTANTE**: Este módulo é **completamente isolado** do sistema principal vupi.us api.

### Princípios de Isolamento

1. **Dependências Isoladas**
   - Cada desenvolvedor instala suas próprias dependências
   - `vendor/` é local ao módulo, não afeta o sistema principal
   - `composer.json` e `composer.lock` são do módulo

2. **Ambiente Isolado**
   - Cada desenvolvedor cria seu próprio `.env`
   - Variáveis de ambiente não interferem no sistema principal
   - `.env` nunca é versionado

3. **Sem Acesso ao Sistema Principal**
   - Desenvolvedores do módulo não têm acesso ao `.env` do sistema vupi.us
   - Desenvolvedores do módulo não têm acesso ao `composer.json` do sistema
   - Módulo funciona de forma independente

---

## 🚀 Setup para Desenvolvedores

### 1. Instalar Dependências do Módulo

```bash
# Entre no diretório do módulo
cd src/Modules/Authenticador

# Instale as dependências (cria vendor/ local)
composer install
```

Isso criará:
- `vendor/` - Dependências do módulo (local)
- `composer.lock` - Lock de versões (local)

### 2. Criar .env do Módulo

```bash
# Copie o template
cp .env.example .env

# Edite com suas configurações
nano .env
```

**Variáveis Obrigatórias**:
```env
# OAuth2 - Google
GOOGLE_OAUTH_ENABLED=true
GOOGLE_CLIENT_ID=seu-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=seu-client-secret
GOOGLE_REDIRECT_URI=http://localhost:3005/api/auth/google/callback

# JWT
JWT_SECRET=sua-chave-secreta-minimo-32-caracteres
JWT_ALGORITHM=HS256
JWT_ACCESS_TOKEN_TTL=3600
JWT_REFRESH_TOKEN_TTL=2592000
```

### 3. Configurar Google OAuth2

1. Acesse: https://console.cloud.google.com/apis/credentials
2. Crie um projeto ou selecione existente
3. Crie credenciais OAuth2
4. Adicione URI de redirecionamento:
   ```
   http://localhost:3005/api/auth/google/callback
   ```
5. Copie Client ID e Client Secret para o `.env`

---

## 📁 Estrutura do Módulo

```
src/Modules/Authenticador/
├── .env                    ← Seu .env (não versionado)
├── .env.example            ← Template
├── .gitignore              ← Ignora .env e vendor/
├── composer.json           ← Dependências do módulo
├── composer.lock           ← Lock (não versionado)
├── vendor/                 ← Dependências (não versionado)
├── README.md               ← Este arquivo
│
├── Bootstrap/
│   └── EnvLoader.php       ← Carrega .env do módulo
│
├── Controllers/
│   ├── OAuth2Controller.php
│   ├── SecurityController.php
│   └── TokenController.php
│
├── Services/
│   ├── OAuth2/
│   │   └── GoogleOAuthService.php
│   ├── AuthenticationService.php
│   ├── TokenService.php
│   └── RateLimitService.php
│
├── Integracao/
│   └── Usuario2Integrador.php
│
└── Routes/
    └── routes.php
```

---

## 🔧 Gerenciamento de Dependências

### Adicionar Nova Dependência

```bash
cd src/Modules/Authenticador
composer require nome/pacote
```

Isso atualiza:
- `composer.json` do módulo
- `composer.lock` do módulo
- `vendor/` do módulo

**Não afeta o sistema principal!**

### Atualizar Dependências

```bash
cd src/Modules/Authenticador
composer update
```

### Remover Dependência

```bash
cd src/Modules/Authenticador
composer remove nome/pacote
```

---

## 🧪 Testando o Módulo

### 1. Verificar Instalação

```bash
# Verificar dependências
ls vendor/league/oauth2-google

# Verificar .env
cat .env
```

### 2. Testar Endpoint OAuth2

```bash
curl http://localhost:3005/api/auth/google
```

**Resposta esperada**:
```json
{
  "status": "success",
  "data": {
    "authorization_url": "https://accounts.google.com/o/oauth2/auth?...",
    "state": "..."
  }
}
```

---

## 📡 Endpoints Disponíveis

### OAuth2 - Públicos

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/auth/google` | Inicia login com Google |
| GET | `/api/auth/google/callback` | Callback do Google |

### OAuth2 - Autenticados

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/api/auth/google/link` | Vincula conta Google |
| DELETE | `/api/auth/google/unlink` | Desvincula conta Google |

### Tokens

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/api/auth/token/validate` | Valida token |
| POST | `/api/auth/token/refresh` | Renova token |
| GET | `/api/auth/tokens` | Lista tokens (auth) |
| POST | `/api/auth/token/revoke` | Revoga token (auth) |

### Segurança

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/auth/sessions` | Lista sessões (auth) |
| DELETE | `/api/auth/sessions/:uuid` | Revoga sessão (auth) |
| GET | `/api/auth/rate-limit` | Info rate limit (auth) |

---

## 🔐 Segurança

### Boas Práticas

1. **Nunca versione o .env**
   - Contém credenciais sensíveis
   - Cada desenvolvedor tem o seu

2. **Nunca versione vendor/**
   - Dependências são instaladas localmente
   - `composer.lock` garante versões consistentes

3. **Proteja suas credenciais**
   - `GOOGLE_CLIENT_SECRET` é sensível
   - `JWT_SECRET` deve ter no mínimo 32 caracteres

4. **Use HTTPS em produção**
   - OAuth2 requer HTTPS
   - Tokens devem ser transmitidos com segurança

---

## 📚 Documentação Adicional

- `OAUTH2_IMPLEMENTACAO.md` - Documentação técnica completa
- `GUIA_RAPIDO_OAUTH2.md` - Guia rápido de uso
- `MODULOS_DEPENDENCIAS_IMPLEMENTADO.md` - Arquitetura de módulos

---

## 🤝 Contribuindo

### Workflow de Desenvolvimento

1. **Clone o repositório**
   ```bash
   git clone <repo>
   cd src/Modules/Authenticador
   ```

2. **Instale dependências**
   ```bash
   composer install
   ```

3. **Configure .env**
   ```bash
   cp .env.example .env
   # Edite .env com suas configurações
   ```

4. **Desenvolva**
   - Faça suas alterações
   - Teste localmente

5. **Commit**
   ```bash
   git add .
   git commit -m "feat: sua feature"
   ```

**Importante**: `.env`, `vendor/` e `composer.lock` não são versionados!

---

## 🐛 Troubleshooting

### Erro: "Class not found"

**Solução**: Reinstale as dependências
```bash
cd src/Modules/Authenticador
rm -rf vendor/
composer install
```

### Erro: "GOOGLE_CLIENT_ID not found"

**Solução**: Verifique se o `.env` existe e está configurado
```bash
cat .env
```

### Erro: "redirect_uri_mismatch"

**Solução**: Verifique se a URI no `.env` está cadastrada no Google Cloud Console

---

## 📞 Suporte

- Documentação: Veja arquivos `.md` na raiz do projeto
- Issues: Abra uma issue no repositório
- Email: dev@example.com

---

**Versão**: 1.0.0  
**Licença**: MIT  
**Desenvolvido com**: ❤️ e isolamento total
