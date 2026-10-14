<?php

namespace Tests\Concurrency\Support;

use RuntimeException;

/**
 * Processus PHP enfant qui exécute un scénario de tests/Concurrency/bin (une action réelle, une connexion
 * MySQL distincte). Le test tient une barrière (verrou MySQL dans une transaction ouverte), attend que les
 * enfants soient bloqués sur la requête attendue, puis la relâche : l'entrelacement est contrôlé, pas
 * laissé au hasard d'un sleep.
 */
final class ScenarioProcess
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private string $stdout = '';

    private string $stderr = '';

    private bool $closed = false;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $environment
     */
    private function __construct(string $script, string $scenario, array $arguments, array $environment)
    {
        $process = proc_open(
            [PHP_BINARY, $script, $scenario, json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Lancement du processus enfant impossible.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $environment
     */
    public static function start(string $scenario, array $arguments, array $environment): self
    {
        return new self(dirname(__DIR__).'/bin/access-scenario.php', $scenario, $arguments, $environment);
    }

    public function isRunning(): bool
    {
        if ($this->closed) {
            return false;
        }
        $this->drain();

        return proc_get_status($this->process)['running'];
    }

    /**
     * @return array{ok: bool, result?: mixed, code?: string, message?: string}
     */
    public function wait(float $timeoutSeconds = 30.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while ($this->isRunning()) {
            if (microtime(true) > $deadline) {
                $this->terminate();
                throw new RuntimeException('Processus enfant toujours actif après '.$timeoutSeconds.' s. Sortie : '.$this->stdout.$this->stderr);
            }
            usleep(20_000);
        }
        $this->drain();
        proc_close($this->process);
        $this->closed = true;

        $lines = array_values(array_filter(array_map('trim', explode("\n", $this->stdout)), static fn (string $line): bool => $line !== ''));
        $decoded = $lines === [] ? null : json_decode($lines[count($lines) - 1], true);
        if (! is_array($decoded) || ! isset($decoded['ok'])) {
            throw new RuntimeException('Réponse illisible du processus enfant : '.$this->stdout.' '.$this->stderr);
        }

        /** @var array{ok: bool, result?: mixed, code?: string, message?: string} */
        return $decoded;
    }

    public function output(): string
    {
        if (! $this->closed) {
            $this->drain();
        }

        return $this->stdout.$this->stderr;
    }

    public function terminate(): void
    {
        if ($this->closed) {
            return;
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->closed = true;
    }

    private function drain(): void
    {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $buffer) {
            $chunk = stream_get_contents($this->pipes[$index]);
            if (is_string($chunk)) {
                $this->{$buffer} .= $chunk;
            }
        }
    }
}
