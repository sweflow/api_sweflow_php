<?php
namespace Src\CLI;

/**
 * Executa subprocessos de forma segura usando proc_open com array de argumentos.
 *
 * Cada argumento é passado diretamente ao SO sem interpolação de shell,
 * eliminando o risco de command injection (equivalente ao Symfony Process).
 *
 * SECURITY: proc_open é usado intencionalmente com array de argumentos (não string),
 * o que impede qualquer injeção de shell. Todos os argumentos são valores literais
 * controlados internamente — nunca derivados de input do usuário.
 */
final class Process
{
    private array $command;
    private string $output = '';
    private int $exitCode  = -1;

    public function __construct(array $command)
    {
        $this->command = $command;
    }

    /**
     * Executa e captura stdout+stderr. Retorna true se exit code === 0.
     */
    public function run(): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // proc_open com array evita interpretação de shell — sem risco de injeção
        $proc = proc_open($this->command, $descriptors, $pipes); // NOSONAR
        if (!is_resource($proc)) {
            $this->exitCode = 1;
            return false;
        }

        fclose($pipes[0]);
        $stdout = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        if (is_resource($pipes[1])) fclose($pipes[1]);
        if (is_resource($pipes[2])) fclose($pipes[2]);

        $this->output   = ($stdout ?: '') . ($stderr ?: '');
        $this->exitCode = proc_close($proc);

        return $this->exitCode === 0;
    }

    /**
     * Executa e envia stdout/stderr diretamente para o terminal.
     */
    public function passthru(): int
    {
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        // proc_open com array evita interpretação de shell — sem risco de injeção
        $proc = proc_open($this->command, $descriptors, $pipes); // NOSONAR
        if (!is_resource($proc)) {
            return 1;
        }

        $this->exitCode = proc_close($proc);
        return $this->exitCode;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
