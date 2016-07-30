<?php

/**
 * @author     Ignas Rudaitis <ignas.rudaitis@gmail.com>
 * @copyright  2010-2016 Ignas Rudaitis
 * @license    http://www.opensource.org/licenses/mit-license.html
 */
namespace Patchwork\CodeManipulation;

require __DIR__ . '/CodeManipulation/Source.php';
require __DIR__ . '/CodeManipulation/StreamWrapper.php';
require __DIR__ . '/CodeManipulation/StreamFilter.php';
require __DIR__ . '/CodeManipulation/Actions/Generic.php';
require __DIR__ . '/CodeManipulation/Actions/CallRerouting.php';
require __DIR__ . '/CodeManipulation/Actions/CodeManipulation.php';

use Patchwork\Exceptions;
use Patchwork\Utils;
use Patchwork\Config;
use Patchwork\CallRerouting;

const OUTPUT_DESTINATION = 'php://memory';
const OUTPUT_ACCESS_MODE = 'rb+';

function transform(Source $s)
{
    foreach (State::$actions as $action) {
        $action($s);
    }
}

function transformString($code)
{
    $source = new Source(token_get_all($code));
    transform($source);
    return (string) $source;
}

function transformForEval($code)
{
    $prefix = "<?php ";
    return substr(transformString($prefix . $code), strlen($prefix));
}

function cacheEnabled()
{
    $location = Config\getCachePath();
    if ($location === null) {
        return false;
    }
    if (!is_dir($location) || !is_writable($location)) {
        throw new Exceptions\CachePathUnavailable($location);
    }
    return true;
}

function getCachedPath($file = null)
{
    $file = str_replace(DIRECTORY_SEPARATOR, '/', $file ?: getPendingImport());
    $segments = explode('/', $file);
    return Config\getCachePath() . '/' . join('/', array_map('urlencode', $segments));
}

function availableCached($file = null)
{
    $file = $file ?: getPendingImport();
    return cacheEnabled() &&
    file_exists(getCachedPath($file)) &&
    filemtime($file) <= filemtime(getCachedPath($file));
}

function storeInCache(Source $source)
{
    $path = str_replace(DIRECTORY_SEPARATOR, '/', $source->file);
    $dirs = explode('/', $path);
    $file = array_pop($dirs);
    $cachePath = Config\getCachePath();
    foreach ($dirs as $dir) {
        $cachePath .= '/' . urlencode($dir);
        if (!is_dir($cachePath)) {
            mkdir($cachePath);
        }
    }
    $cachePath .= '/' . urlencode($file);
    file_put_contents($cachePath, $source);
}

function internalToCache($file = null)
{
    $file = $file ?: getPendingImport();
    if (!cacheEnabled()) {
        return false;
    }
    return strpos($file, Config\getCachePath() . '/') === 0
        || strpos($file, Config\getCachePath() . DIRECTORY_SEPARATOR) === 0;
}

function transformAndCache($file = null)
{
    $file = $file ?: getPendingImport();
    foreach (State::$importListeners as $listener) {
        $listener($file);
    }
    if (!availableCached($file)) {
        $code = file_get_contents($file, true);
        $source = new Source(token_get_all($code));
        $source->file = $file;
        if (shouldTransform($file)) {
            transform($source);
        }
        storeInCache($source);
    }
}

function transformAndOpen($file)
{
    foreach (State::$importListeners as $listener) {
        $listener($file);
    }
    if (!internalToCache($file) && availableCached($file)) {
        return fopen(getCachedPath($file), 'r');
    }
    $resource = fopen(OUTPUT_DESTINATION, OUTPUT_ACCESS_MODE);
    $code = file_get_contents($file, true);
    $source = new Source(token_get_all($code));
    $source->file = $file;
    transform($source);
    if (!internalToCache($file) && cacheEnabled()) {
        storeInCache($source);
        return transformAndOpen($file);
    }
    fwrite($resource, $source);
    rewind($resource);
    return $resource;
}

function shouldTransform($file = null)
{
    $file = $file ?: getPendingImport();
    return !Config\isBlacklisted($file) || Config\isWhitelisted($file);
}

function register($actions)
{
    State::$actions = array_merge(State::$actions, (array) $actions);
}

function onImport($listeners)
{
    State::$importListeners = array_merge(State::$importListeners, (array) $listeners);
}

function onBeginScript($listeners)
{
    State::$scriptBeginningListeners = array_merge(
        State::$scriptBeginningListeners,
        (array) $listeners
    );
}

/**
 * @see http://stackoverflow.com/questions/4049856/replace-phps-realpath/4050444
 * @see http://bugs.php.net/bug.php?id=52769
 *
 * @copyright 2014, Lisachenko Alexander <lisachenko.it@gmail.com>
 * @see https://github.com/goaop/framework/blob/master/src/Instrument/PathResolver.php
 */
function resolvePath($somePath, $shouldCheckExistence = false)
{
    // Do not resolve empty string/false/arrays into the current path
    if (!$somePath) {
        return $somePath;
    }
    if (is_array($somePath)) {
        return array_map(array(__CLASS__, __FUNCTION__), $somePath);
    }
    // Trick to get scheme name and path in one action. If no scheme, then there will be only one part
    $components = explode('://', $somePath, 2);
    list ($pathScheme, $path) = isset($components[1]) ? $components : array(null, $components[0]);
    // Optimization to bypass complex logic for simple paths (eg. not in phar archives)
    if (!$pathScheme && ($fastPath = stream_resolve_include_path($somePath))) {
        return $fastPath;
    }
    $isRelative = !$pathScheme && ($path[0] !== '/') && ($path[1] !== ':');
    if ($isRelative) {
        $path = getcwd() . DIRECTORY_SEPARATOR . $path;
    }
    // resolve path parts (single dot, double dot and double delimiters)
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    if (strpos($path, '.') !== false) {
        $parts     = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = [];
        foreach ($parts as $part) {
            if ('.' == $part) {
                continue;
            } elseif ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        $path = implode(DIRECTORY_SEPARATOR, $absolutes);
    }
    if ($pathScheme) {
        $path = "{$pathScheme}://{$path}";
    }
    if ($shouldCheckExistence && !file_exists($path)) {
        return false;
    }
    return $path;
}

function rewriteAndPrepareImportPath($path)
{
    $path = resolvePath($path);
    State::$pendingImport = $path;
    CallRerouting\State::$preprocessedFiles[$path] = true;
    StreamWrapper::unwrap();
    return sprintf('php://filter/read=%s/resource=%s', StreamFilter::FILTER_NAME, $path);
}

function getPendingImport()
{
    return State::$pendingImport;
}

function notifyAboutScriptBeginning()
{
    foreach (State::$scriptBeginningListeners as $listener) {
        call_user_func($listener);
    }
}

class State
{
    static $actions = [];
    static $importListeners = [];
    static $scriptBeginningListeners = [];
    static $pendingImport;
}
