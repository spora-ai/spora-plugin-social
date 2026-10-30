<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedTest;
use Spora\Plugins\Social\Model\SocialPlatform;
use Throwable;

/**
 * One thing the live run checks, and what it costs when it cannot run.
 *
 * A check that cannot run is skipped, never failed: a missing credential is
 * a fact about the environment, not a defect in the plugin. The skip is
 * recorded with its reason, so the runner can tell "nothing was tested"
 * from "everything was tested" — and a check that runs and finds the
 * contract broken is a failure, with the platform's own answer in it.
 */
final class LiveCheck
{
    /**
     * Nothing the check needs from the environment.
     *
     * For the checks of the harness itself: they exercise the machinery, not
     * a platform.
     */
    public const NOTHING = 0;

    public const CREDENTIALS = 1;
    public const AUTHORIZATION = 2;
    public const TOKEN = 4;
    public const PUBLIC_IMAGE = 8;
    public const PUBLISHED = 16;

    public function __construct(
        private readonly LiveContext $context,
        private readonly string $label,
        private readonly SocialPlatform $platform,
        private readonly string $name,
        private readonly int $needs,
        private readonly ?string $forcedMissing = null,
    ) {}

    public function run(callable $body): void
    {
        $missing = $this->forcedMissing ?? $this->context->missingRequirement($this->platform, $this->needs);

        if ($missing !== null) {
            $this->skip($missing);
        }

        try {
            $body();
        } catch (SkippedTest $skip) {
            $this->context->report->record($this->label, $this->name, LiveReport::SKIPPED, $skip->getMessage());

            throw $skip;
        } catch (Throwable $e) {
            $message = $this->context->secrets->redact($e->getMessage());

            $this->context->report->record($this->label, $this->name, LiveReport::FAILED, $message);

            throw new LiveCheckFailed($message);
        }

        $this->context->report->record($this->label, $this->name, LiveReport::PASSED);
    }

    private function skip(string $reason): never
    {
        $this->context->report->record($this->label, $this->name, LiveReport::SKIPPED, $reason);

        Assert::markTestSkipped($reason);
    }
}
