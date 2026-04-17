<?php

namespace Src\Modules\Usuario\Repositories;

use PDO;
use Src\Kernel\Contracts\UserRepositoryInterface;
use Src\Modules\Usuario\Entities\Usuario;
use Src\Kernel\Utils\RelogioTimeZone;

class UsuarioRepository extends UsuarioAbstractRepository implements UserRepositoryInterface
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * Salva (upsert) um usuário no banco de dados.
     * Usa INSERT ... ON DUPLICATE KEY UPDATE (MySQL) ou ON CONFLICT DO UPDATE (PostgreSQL)
     * para evitar a query extra de verificação de existência dentro da transação.
     */
    public function salvar(Usuario $usuario): void
    {
        $this->executarQuery(function () use ($usuario) {
            $agora  = RelogioTimeZone::agora()->format('Y-m-d H:i:s');
            $uuid   = $usuario->getUuid()->toString();
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $senhaAlteradaEm = $usuario->getSenhaAlteradaEm()
                ? (new \DateTimeImmutable())->setTimestamp($usuario->getSenhaAlteradaEm())->format('Y-m-d H:i:s')
                : null;

            if ($driver === 'pgsql') {
                $sql = "INSERT INTO {$this->tabela}
                            (uuid, nome_completo, email, username, senha_hash, url_avatar, url_capa,
                             biografia, nivel_acesso, ativo, status_verificacao, token_verificacao_email, criado_em)
                        VALUES
                            (:uuid, :nome_completo, :email, :username, :senha_hash, :url_avatar, :url_capa,
                             :biografia, :nivel_acesso, :ativo, :status_verificacao, :token_verificacao_email, :criado_em)
                        ON CONFLICT ({$this->colunaId}) DO UPDATE SET
                            nome_completo           = EXCLUDED.nome_completo,
                            email                   = EXCLUDED.email,
                            username                = EXCLUDED.username,
                            senha_hash              = EXCLUDED.senha_hash,
                            url_avatar              = EXCLUDED.url_avatar,
                            url_capa                = EXCLUDED.url_capa,
                            biografia               = EXCLUDED.biografia,
                            nivel_acesso            = EXCLUDED.nivel_acesso,
                            ativo                   = EXCLUDED.ativo,
                            status_verificacao      = EXCLUDED.status_verificacao,
                            token_verificacao_email = EXCLUDED.token_verificacao_email,
                            senha_alterada_em       = COALESCE(:senha_alterada_em, {$this->tabela}.senha_alterada_em),
                            atualizado_em           = NOW()";
            } else {
                $sql = "INSERT INTO {$this->tabela}
                            (uuid, nome_completo, email, username, senha_hash, url_avatar, url_capa,
                             biografia, nivel_acesso, ativo, status_verificacao, token_verificacao_email, criado_em)
                        VALUES
                            (:uuid, :nome_completo, :email, :username, :senha_hash, :url_avatar, :url_capa,
                             :biografia, :nivel_acesso, :ativo, :status_verificacao, :token_verificacao_email, :criado_em)
                        ON DUPLICATE KEY UPDATE
                            nome_completo           = VALUES(nome_completo),
                            email                   = VALUES(email),
                            username                = VALUES(username),
                            senha_hash              = VALUES(senha_hash),
                            url_avatar              = VALUES(url_avatar),
                            url_capa                = VALUES(url_capa),
                            biografia               = VALUES(biografia),
                            nivel_acesso            = VALUES(nivel_acesso),
                            ativo                   = VALUES(ativo),
                            status_verificacao      = VALUES(status_verificacao),
                            token_verificacao_email = VALUES(token_verificacao_email),
                            senha_alterada_em       = COALESCE(:senha_alterada_em, senha_alterada_em),
                            atualizado_em           = :atualizado_em";
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':uuid',                    $uuid);
            $stmt->bindValue(':nome_completo',           $usuario->getNomeCompleto());
            $stmt->bindValue(':email',                   $usuario->getEmail());
            $stmt->bindValue(':username',                $usuario->getUsername());
            $stmt->bindValue(':senha_hash',              $usuario->getSenhaHash());
            $stmt->bindValue(':url_avatar',              $usuario->getUrlAvatar());
            $stmt->bindValue(':url_capa',                $usuario->getUrlCapa());
            $stmt->bindValue(':biografia',               $usuario->getBiografia());
            $stmt->bindValue(':nivel_acesso',            $usuario->getNivelAcesso());
            $stmt->bindValue(':ativo',                   $usuario->isAtivo(), PDO::PARAM_BOOL);
            $stmt->bindValue(':status_verificacao',      $usuario->getStatusVerificacao());
            $stmt->bindValue(':token_verificacao_email', $usuario->getTokenVerificacaoEmail());
            $stmt->bindValue(':criado_em',               $agora);
            $stmt->bindValue(':senha_alterada_em',       $senhaAlteradaEm);
            if ($driver !== 'pgsql') {
                $stmt->bindValue(':atualizado_em', $agora);
            }
            $stmt->execute();
        }, 'Erro ao salvar usuário no banco de dados');
    }

    /**
     * Salva o token de verificação de e-mail para o usuário
     */
    public function salvarTokenVerificacaoEmail(string $uuid, string $token): void
    {
        $sql = "UPDATE {$this->tabela} SET token_verificacao_email = :token WHERE {$this->colunaId} = :uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':uuid', $uuid);
        $stmt->execute();
    }

    /**
     * Salva o token de recuperação de senha para o usuário
     */
    public function salvarTokenRecuperacaoSenha(string $uuid, string $token): void
    {
        $sql = "UPDATE {$this->tabela} SET token_recuperacao_senha = :token WHERE {$this->colunaId} = :uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':uuid', $uuid);
        $stmt->execute();
    }

    /**
     * Busca usuário pelo token de recuperação de senha
     */
    public function buscarPorTokenRecuperacaoSenha(string $token): ?Usuario
    {
        $sql = "SELECT * FROM {$this->tabela} WHERE token_recuperacao_senha = :token LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':token', $token);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->mapearParaEntity($row) : null;
    }

    /**
     * Remove o token de recuperação de senha do usuário
     */
    public function limparTokenRecuperacaoSenha(string $uuid): void
    {
        $sql = "UPDATE {$this->tabela} SET token_recuperacao_senha = NULL WHERE {$this->colunaId} = :uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':uuid', $uuid);
        $stmt->execute();
    }

    /**
     * Busca usuário pelo token de verificação de e-mail
     */
    public function buscarPorTokenVerificacaoEmail(string $token): ?Usuario
    {
        if (empty($token)) {
            return null;
        }

        $sql = "SELECT * FROM {$this->tabela} WHERE token_verificacao_email = :token LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':token', $token);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->mapearParaEntity($row) : null;
    }

    /**
     * Marca o e-mail do usuário como verificado ou não verificado
     */
    public function marcarEmailComoVerificado(string $uuid, bool $verificado = true): void
    {
        if ($verificado) {
            $sql = "UPDATE {$this->tabela} SET verificado_email = :v, token_verificacao_email = NULL WHERE {$this->colunaId} = :uuid";
        } else {
            $sql = "UPDATE {$this->tabela} SET verificado_email = :v WHERE {$this->colunaId} = :uuid";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':v', $verificado, PDO::PARAM_BOOL);
        $stmt->bindValue(':uuid', $uuid);
        $stmt->execute();
    }

    /**
     * Verifica se email já existe
     */
    public function emailExiste(string $email, ?string $excluirUuid = null): bool
    {
        $sql = "SELECT 1 FROM {$this->tabela} WHERE email = :email";
        $params = [':email' => $email];

        if ($excluirUuid) {
            $sql .= " AND {$this->colunaId} != :uuid";
            $params[':uuid'] = $excluirUuid;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Verifica se username já existe
     */
    public function usernameExiste(string $username, ?string $excluirUuid = null): bool
    {
        $sql = "SELECT 1 FROM {$this->tabela} WHERE username = :username";
        $params = [':username' => $username];

        if ($excluirUuid) {
            $sql .= " AND {$this->colunaId} != :uuid";
            $params[':uuid'] = $excluirUuid;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Busca usuários por nome (paginado)
     *
     * @return Usuario[]
     */
    public function buscarPorNomePaginado(
        string $nome,
        int $pagina = 1,
        int $porPagina = 10
    ): array {
        $offset = ($pagina - 1) * $porPagina;

        $sql = "SELECT * FROM {$this->tabela}
                WHERE nome_completo LIKE :nome
                ORDER BY criado_em DESC
                LIMIT :limite OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nome', '%' . $nome . '%');
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn(array $row) => $this->mapearParaEntity($row),
            $resultados
        );
    }

    /**
     * Retorna os últimos usuários criados
     *
     * @return Usuario[]
     */
    public function ultimosCriados(int $quantidade = 10): array
    {
        $sql = "SELECT * FROM {$this->tabela}
                ORDER BY criado_em DESC
                LIMIT :quantidade";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':quantidade', $quantidade, PDO::PARAM_INT);
        $stmt->execute();

        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn(array $row) => $this->mapearParaEntity($row),
            $resultados
        );
    }

    public function buscarComFiltro(int $pagina, int $porPagina, string $busca = '', string $nivel = ''): array
    {
        $offset = ($pagina - 1) * $porPagina;
        $params = [];
        $where  = [];

        if ($busca !== '') {
            $where[]          = "(username LIKE :busca OR email LIKE :busca)";
            $params[':busca'] = '%' . $busca . '%';
        }
        if ($nivel !== '') {
            $where[]          = "nivel_acesso = :nivel";
            $params[':nivel'] = $nivel;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total
        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->tabela} {$whereClause}");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        // Rows
        $stmtRows = $this->pdo->prepare(
            "SELECT * FROM {$this->tabela} {$whereClause} ORDER BY criado_em DESC LIMIT :limite OFFSET :offset"
        );
        foreach ($params as $k => $v) $stmtRows->bindValue($k, $v);
        $stmtRows->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmtRows->bindValue(':offset', $offset,    PDO::PARAM_INT);
        $stmtRows->execute();

        $usuarios = array_map(
            fn(array $row) => $this->mapearParaEntity($row),
            $stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: []
        );

        return [
            'usuarios'      => $usuarios,
            'total'         => $total,
            'total_paginas' => max(1, (int) ceil($total / $porPagina)),
        ];
    }

    /**
     * Lista usernames ativos para sitemap
     *
     * @return array<int, array{username: string, atualizado_em: ?string, criado_em: ?string}>
     */
    public function listarUsernamesAtivos(int $limite = 50000, int $offset = 0): array
    {
        return $this->executarQuery(function () use ($limite, $offset) {
            $sql = "SELECT username, atualizado_em, criado_em
                    FROM {$this->tabela}
                    WHERE ativo = :ativo
                      AND username IS NOT NULL
                      AND username <> ''
                    ORDER BY criado_em DESC
                    LIMIT :limite OFFSET :offset";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':ativo', true, PDO::PARAM_BOOL);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }, 'Erro ao listar usernames ativos');
    }

}
