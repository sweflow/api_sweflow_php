<?php

namespace Src\Kernel;

class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data = []): void
    {
        // Valida o nome da view: apenas letras, números, hífen e underline
        // Previne path traversal (ex: '../../../etc/passwd')
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $view)) {
            echo '<b>Erro:</b> Nome de view inválido.';
            return;
        }

        $caminho = __DIR__ . "/Views/{$view}.php";

        if (!is_file($caminho)) {
            echo '<b>Erro:</b> View \'' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8') . '\' não encontrada em \'src/Views/\'.';
            return;
        }

        // EXTR_SKIP evita que dados do caller sobrescrevam variáveis locais ($view, $caminho)
        extract($data, EXTR_SKIP);
        include $caminho;
    }
}
