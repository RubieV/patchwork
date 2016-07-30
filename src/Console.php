<?php

/**
 * @author     Ignas Rudaitis <ignas.rudaitis@gmail.com>
 * @copyright  2010-2016 Ignas Rudaitis
 * @license    http://www.opensource.org/licenses/mit-license.html
 */
namespace Patchwork\Console;

use Patchwork\CodeManipulation as CM;

error_reporting(E_ALL | E_STRICT);

$argc > 1 && (($argv[1] == 'prime' && $argc > 2) || $argv[1] == 'clear')
    or exit("\nUsage: php patchwork.phar prime DIR1 DIR2 ... DIRn\n" .
              "       (to recursively prime all PHP files under given directories)\n\n" .
              "       php patchwork.phar clear\n" .
              "       (to clear opcode caches and the cache of Patchwork)\n\n");

if ($argv[1] === 'clear') {
    \Patchwork\Utils\clearOpcodeCaches();
    echo "Opcode cache cleared.\n";
    try {
        if (CM\cacheEnabled()) {
            $cache = \Patchwork\Config\getCachePath();
            # TODO recursion
            foreach (scandir($cache) as $file) {
                if (!in_array($file, ['.', '..'])) {
                    $file = $cache . '/' . $file;
                    if (is_dir($file)) {
                        if (!rmdir($file)) {
                            break;
                        }
                    } else {
                        if (!unlink($file)) {
                            break;
                        }
                    }
                }
            }
            echo "Patchwork's cache cleared.\n";
        }
    } catch (\Patchwork\Exceptions\CachePathUnavailable $e) {}
    return;
}

try {
    CM\cacheEnabled()
        or exit("\nError: no cache location set.\n\n");
} catch (\Patchwork\Exceptions\CachePathUnavailable $e) {
    exit("\nError: " . $e->getMessage() . "\n\n");
}

$files = [];

foreach (array_slice($argv, 2) as $path) {
    $path = realpath($path);
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
        if (substr($file, -4) == '.php' && !CM\internalToCache($file) && !CM\availableCached($file)) {
            $files[] = $file;
        }
    }
}

$count = count($files);

$count > 0 or exit("\nNothing to do.\n\n");

echo "\nPriming ($count files total):\n";

const CONSOLE_WIDTH = 80;

$progress = 0;

for ($i = 0; $i < $count; $i++) {
    CM\transformAndCache($files[$i]->getRealPath());
    while ((int) (($i + 1) / $count * CONSOLE_WIDTH) > $progress) {
        echo '.';
        $progress++;
    }
}

echo "\n\n";
