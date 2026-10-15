<?php

namespace Tests\Concurrency\Support;

use RuntimeException;

/**
 * Processus PHP enfant qui exécute un scénario de tests/Concurrency/bin (une action réelle, une connexion
 * MySQL distincte). Le test tient une barrière (verrou MySQL dans une transaction ouverte), attend que les
 * enfants soient bloqués sur la requête attendue, puis la relâche : l'entrelacement est contrôlé, pas
 * laissé au hasard d'un sleep.
 *
 * Les sorties de l'enfant vont dans des fichiers temporaires, pas dans des tubes : sous Windows,
 * stream_set_blocking(false) est sans effet sur les tubes de proc_open, donc lire un tube BLOQUE jusqu'à la fin de
 * l'enfant — or l'enfant attend justement que le test relâche sa barrière (interblocage du test lui-même). Un
 * fichier se lit sans attente sur tous les systèmes.
 */
final class ScenarioProcess
{
    /** @var resource */
    private $process;

    private string $stdoutFile;

    private string $stderrFile;

    private string $stdout = '';

    private string $stderr = '';

    private bool $closed = false;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $environment
     */
    private function __construct(string $script, string $scenario, array $arguments, array $environment)
    {
        $this->stdoutFile = self::temporaryFile('eeo-scenario-out-');
        $this->stderrFile = self::temporaryFile('eeo-scenario-err-');

        $process = proc_open(
            [PHP_BINARY, $script, $scenario, json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            [0 => ['pipe', 'r'], 1 => ['file', $this->stdoutFile, 'w'], 2 => ['file', $this->stderrFile, 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment + self::platformEnvironment(),
        );
        if (! is_resource($process)) {
            $this->removeFiles();

            throw new RuntimeException('Lancement du processus enfant impossible.');
        }
        fclose($pipes[0]);
        $this->process = $process;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $environment
     * @param  string  $script  scénario de tests/Concurrency/bin (access-scenario.php par défaut)
     */
    public static function start(string $scenario, array $arguments, array $environment, string $script = 'access-scenario.php'): self
    {
        return new self(dirname(__DIR__).'/bin/'.$script, $scenario, $arguments, $environment);
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
        $this->removeFiles();

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
        $this->drainFinal();
        $this->removeFiles();
    }

    private function drain(): void
    {
        $this->stdout = (string) @file_get_contents($this->stdoutFile);
        $this->stderr = (string) @file_get_contents($this->stderrFile);
    }

    private function drainFinal(): void
    {
        $this->drain();
    }

    private function removeFiles(): void
    {
        @unlink($this->stdoutFile);
        @unlink($this->stderrFile);
    }

    /**
     * Variables système sans lesquelles un enfant lancé avec un environnement explicite ne fonctionne pas :
     * sous Windows, sans SystemRoot ni windir, PHP ne peut ouvrir aucun socket (« [2002] Unknown error while
     * connecting ») ; TEMP et TMP servent aux fichiers temporaires. Ailleurs, rien n'est ajouté.
     *
     * @return array<string, string>
     */
    private static function platformEnvironment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }
        $environment = [];
        foreach (['SystemRoot', 'windir', 'TEMP', 'TMP'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private static function temporaryFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Fichier temporaire impossible à créer pour le processus enfant.');
        }

        return $path;
    }
}
