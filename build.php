<?php

/**
 * Buduje paczkę instalacyjną wtyczki.
 *
 * Uruchomienie (rozszerzenie zip bywa wyłączone w CLI Laragona):
 *   php -d extension_dir="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/ext" -d extension=php_zip.dll build.php
 *
 * Do archiwum trafia wyłącznie to, co deklaruje manifest. Nazwy wpisów są zapisywane
 * z ukośnikiem "/", bo instalator Joomli nie rozpakuje archiwum ze znakami "\".
 *
 * @package     plg_task_akeebacron
 * @author      Paweł Półtoraczyk <https://web-service.com.pl>
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 2 or later
 */

$root     = __DIR__;
$manifest = $root . '/akeebacron.xml';

if (!is_file($manifest)) {
    fwrite(STDERR, "Nie znaleziono manifestu akeebacron.xml\n");

    exit(1);
}

$xml     = simplexml_load_file($manifest);
$version = (string) $xml->version;

$sources = ['akeebacron.xml'];

foreach ($xml->files->folder as $folder) {
    $sources[] = (string) $folder;
}

$distDir = $root . '/dist';

if (!is_dir($distDir) && !mkdir($distDir, 0755, true)) {
    fwrite(STDERR, "Nie udało się utworzyć katalogu dist\n");

    exit(1);
}

$target = $distDir . '/plg_task_akeebacron-' . $version . '.zip';

@unlink($target);

$zip = new ZipArchive();

if ($zip->open($target, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Nie udało się utworzyć archiwum $target\n");

    exit(1);
}

$count = 0;

foreach ($sources as $source) {
    $path = $root . '/' . $source;

    if (is_file($path)) {
        $zip->addFile($path, $source);
        $count++;

        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        // Instalator Joomli wymaga "/" w nazwach wpisów, Windows daje tu "\".
        $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));

        $zip->addFile($file->getPathname(), $relative);
        $count++;
    }
}

$zip->close();

printf("Zbudowano %s (%d plików, %.1f kB)%s", basename($target), $count, filesize($target) / 1024, PHP_EOL);
