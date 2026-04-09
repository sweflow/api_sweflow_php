<?php

namespace Src\Modules\Usuario\Repositories;

use PDO;
use PDOException;
use Src\Modules\Usuario\Entities\Usuario;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Src\Kernel\Database\Exceptions\DatabaseQueryException;
use InvalidArgumentException;
use Src\Modules\Usuario\Repositories\UsuarioRepositoryInterface;

abstract class UsuarioAbstractRepository implements UsuarioRepositoryInterface
{
    protected string $tabela = 'usuarios';

    /**
     * Instância da conexão PDO
     */
    protected PDO $pdo;

    /**
     * Nome da coluna de identificação (Padrão: UUID)
     */
    protected string $colunaId = 'uuid';

    /**
     * Colunas permitidas para filtros dinâmicos
     * chave = nome público | valor = nome real no banco
     */
    protected array $colunasPermitidas = [
        'email'         => 'email',
        'username'      => 'username',
        'nivel_acesso'  => 'nivel_acesso',
        'ativo'         => 'ativo',
        'status_verificacao' => 'status_verificacao',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Expõe o PDO para uso por serviços que precisam de acesso direto (ex: deleção em cascata). */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Executa uma operação de banco e converte PDOException
     * em DatabaseQueryException
     */
    protected function executarQuery(callable $operacao, string $mensagemErro = 'Erro na operação de banco')
    {
        try {
            return $operacao();
        } catch (PDOException $e) {
            // Violação de unicidade
            if ($e->getCode() === '23000') {
                throw new DatabaseQueryException(
                    'Violação de unicidade no banco de dados',
                    23000,
                    $e
                );
            }

            throw new DatabaseQueryException(
                $mensagemErro,
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * @return Usuario[]
     */
    public function buscarTodos(int $limite = 100, int $offset = 0): array
    {
        return $this->executarQuery(function () use ($limite, $offset) {
            $sql = "SELECT * FROM {$this->tabela} 
                    ORDER BY criado_em DESC
                    LIMIT :limite OFFSET :offset";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(
                fn(array $row) => $this->mapearParaEntity($row),
                $resultados
            );
        }, 'Erro ao buscar usuários');
    }

    public function buscarPorUuid(string $uuid): ?Usuario
    {
        return $this->buscarUmPor($this->colunaId, $uuid);
    }

    public function buscarPorUsername(string $username): ?Usuario
    {
        return $this->buscarUmPor('username', $username);
    }

    public function buscarPorEmail(string $email): ?Usuario
    {
        return $this->buscarUmPor('email', $email);
    }

    public function buscarTodosPor(string $coluna, $valor): array
    {
        $colunaBanco = $this->resolverColuna($coluna);

        return $this->executarQuery(function () use ($colunaBanco, $valor) {
            $sql = "SELECT * FROM {$this->tabela}
                    WHERE {$colunaBanco} = :valor";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':valor', $valor);
            $stmt->execute();

            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(
                fn(array $row) => $this->mapearParaEntity($row),
                $resultados
            );
        }, "Erro ao buscar usuários por {$colunaBanco}");
    }

    public function deletar(string $uuid): void
    {
        $this->executarQuery(function () use ($uuid) {
            $sql = "DELETE FROM {$this->tabela}
                    WHERE {$this->colunaId} = :uuid";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':uuid', $uuid);
            $stmt->execute();
        }, 'Erro ao deletar usuário');
    }

    public function contar(): int
    {
        return $this->executarQuery(function () {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM {$this->tabela}"
            );
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        }, 'Erro ao contar usuários');
    }


    protected function buscarUmPor(string $coluna, $valor): ?Usuario
    {
        $colunaBanco = $this->resolverColuna($coluna);

        return $this->executarQuery(function () use ($colunaBanco, $valor) {
            // Username já é normalizado para lowercase na entity — comparação direta usa índice
            $sql = "SELECT * FROM {$this->tabela}
                    WHERE {$colunaBanco} = :valor
                    LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            // Normaliza username para lowercase antes da comparação
            $bindValue = ($colunaBanco === 'username') ? strtolower((string) $valor) : $valor;
            $stmt->bindValue(':valor', $bindValue);
            $stmt->execute();

            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

            return $resultado === false
                ? null
                : $this->mapearParaEntity($resultado);
        }, "Erro ao buscar usuário por {$colunaBanco}");
    }

    /**
     * Resolve e valida a coluna permitida contra allowlist explícita.
     *
     * SECURITY: $colunaBanco nunca vem de input do usuário — é sempre
     * resolvido a partir de $colunasPermitidas (mapa interno) ou $colunaId
     * (constante da classe). O formato é validado como segunda camada de defesa.
     */
    protected function resolverColuna(string $coluna): string
    {
        // Camada 1: allowlist — só colunas explicitamente mapeadas passam
        if ($coluna === $this->colunaId) {
            $colunaBanco = $this->colunaId;
        } elseif (isset($this->colunasPermitidas[$coluna])) {
            $colunaBanco = $this->colunasPermitidas[$coluna];
        } else {
            throw new InvalidArgumentException('Coluna inválida para busca');
        }

        // Camada 2: valida formato — só letras, números e underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colunaBanco)) {
            throw new InvalidArgumentException('Nome de coluna com formato inválido.');
        }

        return $colunaBanco;
    }

    /**
     * Converte array do banco em Entity Usuario
     */
    protected function mapearParaEntity(array $dados): Usuario
    {
        return Usuario::reconstituir(
            Uuid::fromString($dados['uuid']),
            $dados['nome_completo'],
            $dados['username'],
            $dados['email'],
            $dados['senha_hash'],
            $dados['nivel_acesso'],
            (bool) $dados['ativo'],
            // Suporta tanto 'verificado_email' (legado) quanto 'status_verificacao' para compatibilidade
            isset($dados['verificado_email'])
                ? (bool) $dados['verificado_email']
                : (($dados['status_verificacao'] ?? '') === 'Verificado'),
            new DateTimeImmutable($dados['criado_em']),
            $dados['url_avatar'] ?? null,
            $dados['url_capa'] ?? null,
            $dados['biografia'] ?? null,
            $dados['token_recuperacao_senha'] ?? null,
            $dados['token_verificacao_email'] ?? null,
            isset($dados['atualizado_em'])
                ? new DateTimeImmutable((string) $dados['atualizado_em'])
                : null,
            $dados['status_verificacao'] ?? 'Não verificado'
        );
    }

    abstract public function salvar(Usuario $usuario): void;
}
