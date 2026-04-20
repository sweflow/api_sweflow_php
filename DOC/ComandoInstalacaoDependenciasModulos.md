# Comando: Instalar Dependências dos Módulos

## Descrição

O comando **opção 30** do `php vupi setup` detecta automaticamente todos os módulos que possuem arquivo `composer.json` e instala suas dependências de forma isolada.

## Como Usar

### Via Menu Interativo

```bash
php vupi setup
# Escolha a opção 30
```

### O que o Comando Faz

1. **Escaneia** o diretório `src/Modules/`
2. **Detecta** módulos com arquivo `composer.json`
3. **Instala** dependências executando `composer install` em cada módulo
4. **Exibe** relatório com:
   - ✅ Módulos instalados com sucesso
   - ⏭️ Módulos ignorados (sem composer.json)
   - ✖ Módulos com falha na instalação

## Exemplo de Saída

```
╔══════════════════════════════════════════════════════════════╗
║  Instalando Dependências dos Módulos                        ║
╚══════════════════════════════════════════════════════════════╝

  ⏭️  Auth: sem composer.json
  📦 Authenticador: instalando dependências...
    ✅ Dependências instaladas com sucesso
  ⏭️  Documentacao: sem composer.json
  📦 Email: instalando dependências...
    ✅ Dependências instaladas com sucesso
  ⏭️  IdeModuleBuilder: sem composer.json
  ⏭️  LinkEncurtador: sem composer.json
  ⏭️  Usuario: sem composer.json
  ⏭️  Usuarios2: sem composer.json

╔══════════════════════════════════════════════════════════════╗
║  Resumo                                                      ║
╚══════════════════════════════════════════════════════════════╝

  ✅ Instalados: 2
  ⏭️  Ignorados: 6

✔ Dependências dos módulos instaladas com sucesso!
```

## Quando Usar

- **Após clonar o repositório**: Instalar dependências de todos os módulos de uma vez
- **Após criar novo módulo**: Instalar dependências do módulo recém-criado
- **Após atualizar composer.json**: Reinstalar dependências após mudanças
- **Em CI/CD**: Automatizar instalação de dependências no pipeline

## Requisitos

- **Composer** instalado e disponível no PATH
- Módulos devem ter arquivo `composer.json` válido
- Permissões de escrita no diretório do módulo

## Estrutura Esperada

```
src/Modules/
├── Authenticador/
│   ├── composer.json          ← Detectado
│   ├── vendor/                ← Criado automaticamente
│   └── ...
├── Email/
│   ├── composer.json          ← Detectado
│   ├── vendor/                ← Criado automaticamente
│   └── ...
└── Auth/
    └── ...                    ← Ignorado (sem composer.json)
```

## Isolamento de Dependências

Cada módulo tem suas próprias dependências instaladas localmente:

- ✅ **Isolamento**: Dependências não interferem entre módulos
- ✅ **Versionamento**: Cada módulo pode usar versões diferentes
- ✅ **Segurança**: Módulos não acessam dependências de outros módulos
- ✅ **Portabilidade**: Módulo pode ser movido/copiado com suas dependências

## Troubleshooting

### Erro: "Composer não encontrado"

**Solução**: Instale o Composer:
```bash
# Windows
https://getcomposer.org/Composer-Setup.exe

# Linux/Mac
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Erro: "Falha ao instalar dependências"

**Causas possíveis**:
- composer.json inválido
- Dependência não encontrada no Packagist
- Falta de permissões de escrita
- Problemas de rede

**Solução**: Execute manualmente para ver o erro detalhado:
```bash
cd src/Modules/NomeDoModulo
composer install
```

### Módulo não detectado

**Causa**: Módulo não possui arquivo `composer.json`

**Solução**: Crie o arquivo `composer.json` no módulo:
```json
{
    "name": "vupi/nome-modulo",
    "description": "Descrição do módulo",
    "require": {
        "php": ">=8.1"
    }
}
```

## Integração com CI/CD

### GitHub Actions

```yaml
- name: Instalar dependências dos módulos
  run: |
    php vupi setup --modules-deps
```

### GitLab CI

```yaml
install_modules:
  script:
    - php vupi setup --modules-deps
```

## Notas

- O comando **não** instala dependências do sistema principal (raiz)
- Apenas módulos em `src/Modules/` são processados
- Módulos em `vendor/` ou `storage/modules/` não são afetados
- O comando é **idempotente**: pode ser executado múltiplas vezes

## Ver Também

- [Guia de Desenvolvimento de Módulos](./GUIA_DESENVOLVEDOR_MODULO.md)
- [Isolamento de Módulos](./Modulos.md)
- [Comandos de Módulos](./ComandosModulos.md)
