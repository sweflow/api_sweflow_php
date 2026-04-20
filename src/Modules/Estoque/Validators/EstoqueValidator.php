<?php

namespace Src\Modules\Estoque\Validators;

final class EstoqueValidator
{
    public static function validarCriacao(array $data): ?string
    {
        $produto = trim($data['produto'] ?? '');
        if ($produto === '') { return 'O campo "produto" e obrigatorio.'; }
        if (mb_strlen($produto) > 255) { return 'O campo "produto" deve ter no maximo 255 caracteres.'; }
        return null;
    }

    public static function validarAtualizacao(array $data): ?string
    {
        if (isset($data['produto']) && mb_strlen($data['produto']) > 255) {
            return 'O campo "produto" deve ter no maximo 255 caracteres.';
        }
        return null;
    }

    public static function sanitizar(array $data): array
    {
        $clean = [];
        if (isset($data['produto'])) { 
            $clean['produto'] = trim(strip_tags($data['produto'])); 
        }
        if (isset($data['quantidade'])) { 
            $clean['quantidade'] = (float) $data['quantidade']; 
        }
        return $clean;
    }
}
