<?php

/**
 * @author     Ignas Rudaitis <ignas.rudaitis@gmail.com>
 * @copyright  2010-2016 Ignas Rudaitis
 * @license    http://www.opensource.org/licenses/mit-license.html
 */
namespace Patchwork\CodeManipulation;

use Patchwork\Utils;

class StreamFilter extends \php_user_filter
{
    const FILTER_NAME = 'patchwork';

    private $consumed;

    static function register()
    {
        stream_filter_register(self::FILTER_NAME, __CLASS__);
    }

    function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->consumed += strlen($bucket->data);
        }
        if ($closing || feof($this->stream)) {
            $consumed = $this->consumed;
            transformAndCache();
            $this->consumed = 0;
            $bucket = stream_bucket_new($this->stream, Utils\importerSnippet(getCachedPath()));
            stream_bucket_append($out, $bucket);
            return PSFS_PASS_ON;
        }
        return PSFS_FEED_ME;
    }
}
