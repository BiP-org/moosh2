#!/usr/bin/env php
<?php
/**
 * Build script for moosh2.phar
 *
 * Creates a single PHAR file containing all code from:
 * includes/, src/, vendor/ directories plus the entry point.
 *
 * Usage: php build-phar.php
 *
 * Note: phar.readonly must be Off in php.ini or pass -d phar.readonly=0
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (ini_get('phar.readonly')) {
    echo "Error: phar.readonly is enabled. Run with:\n";
    echo "  php -d phar.readonly=0 build-phar.php\n";
    exit(1);
}

$pharFile = __DIR__ . '/moosh2.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile, 0, 'moosh2.phar');
$phar->startBuffering();

$baseDir = __DIR__ . '/..';

$dirs = ['src', 'includes', 'vendor'];

foreach ($dirs as $dir) {
    $fullPath = $baseDir . '/' . $dir;
    if (!is_dir($fullPath)) {
        echo "Warning: directory '$dir' not found, skipping.\n";
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relativePath = $dir . '/' . substr($file->getPathname(), strlen($fullPath) + 1);
        $phar->addFile($file->getPathname(), $relativePath);
    }

    echo "Added $dir/\n";
}

// Add composer autoload
//$phar->addFile($baseDir . '/composer.json', 'composer.json');

$stub = <<<'STUB'
#!/usr/bin/env php
<?php
/**
 * moosh2 — Moodle Shell (PHAR)
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

Phar::mapPhar('moosh.phar');

require 'phar://moosh.phar/vendor/autoload.php';

use Moosh2\Application;

$app = new Application();
$app->run();

__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

chmod($pharFile, 0755);

$size = round(filesize($pharFile) / 1024 / 1024, 2);
echo "\nBuilt moosh2.phar ({$size} MB)\n";
echo "Run with: php moosh2.phar <command>\n";
