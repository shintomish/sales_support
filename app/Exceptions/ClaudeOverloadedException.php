<?php

namespace App\Exceptions;

/**
 * Claude API が overloaded_error / 429 を返し、内部リトライも尽きた場合に投げる例外。
 * コントローラ側で catch して HTTP 503 を返すために使う。
 */
class ClaudeOverloadedException extends \RuntimeException
{
}
