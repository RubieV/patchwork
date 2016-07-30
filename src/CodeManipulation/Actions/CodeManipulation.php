<?php

/**
 * @author     Ignas Rudaitis <ignas.rudaitis@gmail.com>
 * @copyright  2010-2016 Ignas Rudaitis
 * @license    http://www.opensource.org/licenses/mit-license.html
 */
namespace Patchwork\CodeManipulation\Actions\CodeManipulation;

use Patchwork\CodeManipulation\Actions\Generic;
use Patchwork\CodeManipulation\Source;
use Patchwork\Utils;

const EVAL_ARGUMENT_WRAPPER = '\Patchwork\CodeManipulation\transformForEval';
const STREAM_FILTER_PATH_REWRITER = '\Patchwork\CodeManipulation\rewriteAndPrepareImportPath';
const SCRIPT_BEGINNING_TRIGGER = '\Patchwork\CodeManipulation\notifyAboutScriptBeginning()';

function propagateThroughEval()
{
    return Generic\wrapUnaryConstructArguments(T_EVAL, EVAL_ARGUMENT_WRAPPER);
}

function propagateThroughStreamFilter()
{
    return function(Source $s) {
        $imports = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
        foreach ($s->all($imports) as $import) {
            $end = min($s->next([';', ','], $import), $s->endOfLevel($import));
            $splice = ' ' . STREAM_FILTER_PATH_REWRITER . Generic\LEFT_ROUND;
            $s->splice($splice, $import + 1, 0, Source::PREPEND);
            $s->splice(Generic\RIGHT_ROUND, $end, 0, Source::APPEND);
        }
    };
}

function expandMagicFilesystemConstants()
{
    return function(Source $s) {
        foreach ($s->all(T_DIR) as $token) {
            $s->splice(Utils\toStringExpression(dirname($s->file)), $token, 1);
        }
        foreach ($s->all(T_FILE) as $token) {
            $s->splice(Utils\toStringExpression($s->file), $token, 1);
        }
    };
}

function injectScriptBeginningTriggers()
{
    return Generic\injectFalseExpressionAtBeginnings(SCRIPT_BEGINNING_TRIGGER);
}

function flush()
{
    return function(Source $s) {
        $s->flush();
    };
}
