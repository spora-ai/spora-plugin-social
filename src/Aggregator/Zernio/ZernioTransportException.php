<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator\Zernio;

use RuntimeException;

/**
 * The call never reached Zernio, or its answer never came back.
 *
 * The distinction matters more than the failure itself: a request that
 * never landed may still have been acted on, so the publisher leaves the
 * target in `publishing` rather than calling it failed. A refusal, by
 * contrast, is Zernio's own answer and does fail the target.
 */
final class ZernioTransportException extends RuntimeException {}
