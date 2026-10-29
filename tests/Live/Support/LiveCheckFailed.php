<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use RuntimeException;

/**
 * A check that ran and found the contract broken.
 *
 * The message carries the claim that failed and never the value that
 * failed it: a value can be a token, and a token in a failure message is a
 * token in the operator's terminal history.
 */
final class LiveCheckFailed extends RuntimeException {}
