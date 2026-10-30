<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

/**
 * What the live run checked, in a form the runner can read back.
 *
 * The report is what makes a skipped run honest: a run that checked nothing
 * has to say so, and the runner counts the skips from here rather than from
 * the test runner's own summary line.
 */
final class LiveReport
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /** @var list<array{platform: string, name: string, outcome: string, detail: string, at: string}> */
    private array $checks = [];

    /** @var list<array{platform: string, text: string}> */
    private array $notes = [];

    public function __construct(
        private readonly string $path,
        private readonly LiveSecrets $secrets,
    ) {}

    public function record(string $platform, string $name, string $outcome, string $detail = ''): void
    {
        $this->checks[] = [
            'platform' => $platform,
            'name'     => $name,
            'outcome'  => $outcome,
            'detail'   => $this->secrets->redact($detail),
            'at'       => gmdate('c'),
        ];

        $this->write();
    }

    /**
     * Something the operator has to know that is not a failure: a post the
     * platform refused to take down, a metric that is not there yet.
     */
    public function note(string $platform, string $text): void
    {
        $this->notes[] = ['platform' => $platform, 'text' => $this->secrets->redact($text)];

        $this->write();
    }

    public function write(): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $payload = json_encode(
            ['checks' => $this->checks, 'notes' => $this->notes],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        file_put_contents($this->path, ($payload === false ? '{}' : $payload) . "\n");
    }

    /**
     * Where the report goes.
     *
     * Inside the plugin's own `build/` directory, which the repository
     * ignores: a report of a live run is a record of what happened on the
     * operator's machine and has no business in a commit.
     */
    public static function defaultPath(): string
    {
        $override = getenv('SPORA_LIVE_REPORT');

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return dirname(__DIR__, 3) . '/build/live/report.json';
    }
}
