<?php

namespace Task\Validators;

final class TaskValidator
{
    public static function validarCriacao(array $data): ?string
    {
        $nome = trim($data['nome'] ?? '');
        if ($nome === '') { return 'O campo "nome" e obrigatorio.'; }
        if (mb_strlen($nome) > 255) { return 'O campo "nome" deve ter no maximo 255 caracteres.'; }
        return null;
    }

    public static function validarAtualizacao(array $data): ?string
    {
        if (isset($data['nome']) && mb_strlen($data['nome']) > 255) {
            return 'O campo "nome" deve ter no maximo 255 caracteres.';
        }
        return null;
    }

    public static function sanitizar(array $data): array
    {
        $clean = [];
        if (isset($data['nome'])) { $clean['nome'] = trim(strip_tags($data['nome'])); }
        return $clean;
    }
}
