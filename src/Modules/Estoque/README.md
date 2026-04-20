# Modulo Estoque

Modulo para a Vupi.us API.

## Rotas

| Metodo | URI | Descricao |
|--------|-----|-----------|
| GET | `/api/estoque` | Listar (paginado) |
| GET | `/api/estoque/{id}` | Buscar por ID |
| POST | `/api/estoque` | Criar |
| PUT | `/api/estoque/{id}` | Atualizar |
| DELETE | `/api/estoque/{id}` | Deletar (admin) |

## Dependencias externas

Para adicionar bibliotecas externas (ex: PHPMailer, Guzzle), edite o `composer.json`
deste modulo e declare na secao `require`. Ao fazer deploy, as dependencias serao
instaladas automaticamente no projeto.

## Conectar aplicacao externa

1. Solicite ao suporte da Vupi.us API a liberacao no CORS da URL do frontend.
2. O admin adicionara a URL via Dashboard > Configuracoes > CORS.
3. Apos aprovacao, sua aplicacao podera fazer requisicoes a API.
