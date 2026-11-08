<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Models\Agent;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Services\PrincipalContext;
use Spora\Tools\AbstractTool;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * The shared plumbing of the five social tools.
 *
 * Tools never throw: every failure comes back as a `ToolResult` the model
 * can reason about. A tool that threw would take the tick with it.
 */
abstract class AbstractSocialTool extends AbstractTool
{
    /**
     * The principal the tool acts for.
     *
     * The context's principal id is the agent's own, so a tool never
     * reaches across into another principal's accounts.
     */
    protected function principalId(int $agentId, ?PrincipalContext $context): int
    {
        if ($context !== null && $context->isResolvable()) {
            return $context->principalId;
        }

        $principalId = Agent::query()->where('id', $agentId)->value('principal_id');

        if ($principalId === null) {
            throw SocialException::forbidden('This tool needs a principal to act for.');
        }

        return (int) $principalId;
    }

    /**
     * A failure, in the shape the model reads.
     */
    protected function failure(Throwable $e): ToolResult
    {
        $code = $e instanceof SocialException ? $e->errorCode : 'ERROR';

        return new ToolResult(false, "Error ({$code}): " . $e->getMessage());
    }

    /**
     * The keys the caller gave, lower-cased.
     *
     * @param  array<string> $fallback what to answer with when the caller names none
     * @return list<string>
     */
    protected function keyList(mixed $raw, array $fallback = []): array
    {
        if (!is_array($raw)) {
            return $fallback;
        }

        $keys = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $keys[] = strtolower($value);
            }
        }

        return $keys !== [] ? $keys : $fallback;
    }

    /**
     * @return list<int>
     */
    protected function intList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
