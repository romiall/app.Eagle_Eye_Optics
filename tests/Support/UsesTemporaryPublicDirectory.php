<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Répertoire public jetable : isole un test du dossier public/ réel (build Vite, fichier public/hot du
 * serveur de développement). La CI PHP ne construit pas le frontend, donc un test qui a besoin de
 * public/build y dépose lui-même ses fichiers. Le répertoire est supprimé à la fin du test.
 */
trait UsesTemporaryPublicDirectory
{
    protected function temporaryPublicDirectory(): string
    {
        $directory = sys_get_temp_dir().'/eeo-public-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);
        $this->app->usePublicPath($directory);
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($directory));

        return $directory;
    }
}
