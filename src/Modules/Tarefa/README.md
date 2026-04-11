# Modulo Tarefa

Modulo para a Vupi.us API.

## Rotas

| Metodo | URI | Descricao |
|--------|-----|-----------|
| GET | `/api/tarefa` | Listar (paginado) |
| GET | `/api/tarefa/{id}` | Buscar por ID |
| POST | `/api/tarefa` | Criar |
| PUT | `/api/tarefa/{id}` | Atualizar |
| DELETE | `/api/tarefa/{id}` | Deletar (admin) |

## Conectar aplicacao externa

1. Solicite ao suporte da Vupi.us API a liberacao no CORS da URL do frontend.
2. O admin adicionara a URL via Dashboard > Configuracoes > CORS.
3. Apos aprovacao, sua aplicacao podera fazer requisicoes a API.
