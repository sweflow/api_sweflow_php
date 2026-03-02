<?php
namespace Src;

class View
{
    public static function render(string $view, array $data = []): void
    {
        extract($data);
        if (!empty($view)) {
            $caminho = __DIR__ . "/Views/{$view}.php";
            if (file_exists($caminho)) {
                include $caminho;
            } else {
                echo "<b>Erro:</b> View '{$view}' não encontrada em 'src/Views/'.";
            }
        }
    }
}
