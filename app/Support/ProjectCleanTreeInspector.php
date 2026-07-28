<?php

namespace App\Support;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProjectCleanTreeInspector
{
    /**
     * @param  list<string>  $trackedFiles
     * @return list<string>
     */
    public function forbiddenTrackedFiles(array $trackedFiles): array
    {
        $forbidden = [];

        foreach ($trackedFiles as $path) {
            $path = str_replace('\\', '/', $path);
            $isEnvExample = in_array($path, [
                '.env.example',
                '.env.docker.example',
                '.env.testing.example',
            ], true);

            if ($path === '.env'
                || (! $isEnvExample && str_starts_with($path, '.env.'))
                || $path === '.env.local.bak'
                || $path === 'public/storage'
                || str_starts_with($path, 'public/storage/')
                || $path === 'public/hot'
                || str_starts_with($path, 'public/hot/')
                || preg_match('#^bootstrap/cache/.+\.php$#', $path)
                || preg_match('#(^|/)vendor/#', $path)
                || preg_match('#(^|/)node_modules/#', $path)
                || preg_match('/\.(?:tar\.gz|tgz|zip)$/i', $path)
                || str_ends_with($path, '.patch')
                || str_ends_with($path, ':Zone.Identifier')) {
                $forbidden[] = $path;
            }
        }

        return array_values(array_unique($forbidden));
    }

    /**
     * @return list<string>
     */
    public function strictLocalViolations(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $violations = [];
        $forbiddenLocalPaths = [
            '.env',
            '.env.local.bak',
            'public/hot',
            'public/storage',
            'bootstrap/cache/packages.php',
            'bootstrap/cache/services.php',
            'bootstrap/cache/config.php',
            'bootstrap/cache/events.php',
        ];

        foreach ($forbiddenLocalPaths as $path) {
            $absolutePath = $root.DIRECTORY_SEPARATOR.$path;

            if (file_exists($absolutePath) || is_link($absolutePath)) {
                $violations[] = 'Запрещённый локальный файл/ссылка для strict clean tree: '.$path;
            }
        }

        foreach (glob($root.DIRECTORY_SEPARATOR.'bootstrap/cache/routes*.php') ?: [] as $path) {
            $violations[] = 'Запрещённый generated route cache: '.$this->relativePath($root, $path);
        }

        foreach (glob($root.DIRECTORY_SEPARATOR.'*.patch') ?: [] as $path) {
            $violations[] = 'Patch-файл в корне проекта: '.basename($path);
        }

        foreach (['*.tar.gz', '*.tgz', '*.zip'] as $pattern) {
            foreach (glob($root.DIRECTORY_SEPARATOR.$pattern) ?: [] as $path) {
                $violations[] = 'Архив в корне проекта: '.basename($path);
            }
        }

        foreach ($this->zoneIdentifierPaths($root) as $path) {
            $violations[] = 'Zone.Identifier в проекте: '.$path;
        }

        return array_values(array_unique($violations));
    }

    /**
     * @return list<string>
     */
    private function zoneIdentifierPaths(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $directory = new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $item): bool {
                if (! $item->isDir()) {
                    return true;
                }

                return ! in_array($item->getFilename(), ['.git', 'node_modules', 'vendor'], true);
            },
        );
        $iterator = new RecursiveIteratorIterator($filter);
        $paths = [];

        foreach ($iterator as $item) {
            if (str_ends_with($item->getFilename(), ':Zone.Identifier')) {
                $paths[] = $this->relativePath($root, $item->getPathname());
            }
        }

        return $paths;
    }

    private function relativePath(string $root, string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}
