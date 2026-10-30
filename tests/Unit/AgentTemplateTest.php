<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Skills\SkillValidator;

it('registers the template and the skill through the plugin hooks', function () {
    $plugin = new Spora\Plugins\Social\SocialPlugin();

    $templates = $plugin->agentTemplatePaths();
    $skills = $plugin->skillPaths();

    expect($templates)->toHaveCount(1)
        ->and($skills)->toHaveCount(1)
        ->and(is_file($templates[0] . '/social-poster.json'))->toBeTrue()
        ->and(is_file($skills[0] . '/social-poster/SKILL.md'))->toBeTrue();
});

it('ships a template core accepts', function () {
    $result = (new AgentTemplateValidator())->validate(socialAgentTemplate());

    expect($result->isValid())->toBeTrue();
});

it('ships a skill core accepts', function () {
    $skill = socialSkill();

    $result = (new SkillValidator())->validate(
        $skill['frontmatter'],
        $skill['body'],
        'social-poster',
    );

    expect($result->isValid())->toBeTrue();
});

it('names only tool classes the plugin registers', function () {
    foreach (socialAgentTemplate()['tools'] ?? [] as $tool) {
        expect(class_exists($tool['tool_class']))->toBeTrue();
        expect(is_subclass_of($tool['tool_class'], Spora\Tools\ToolInterface::class))->toBeTrue();
    }
});

it('activates exactly the operations the tool declares', function () {
    foreach (socialAgentTemplate()['tools'] ?? [] as $tool) {
        $declared = array_keys(operationsOf($tool['tool_class']));
        $activated = array_map(
            static fn(array $operation): string => $operation['name'],
            $tool['operations'],
        );

        sort($declared);
        sort($activated);

        expect($activated)->toBe($declared);
    }
});

it('activates every tool the plugin ships', function () {
    $activated = array_map(
        static fn(array $tool): string => $tool['tool_class'],
        socialAgentTemplate()['tools'] ?? [],
    );

    $shipped = (new Spora\Plugins\Social\SocialPlugin())->tools();

    foreach ($shipped as $class) {
        expect($activated)->toContain($class);
    }
});

it('grants the skill to the agent it ships', function () {
    $grants = [];

    foreach (socialAgentTemplate()['tools'] ?? [] as $tool) {
        foreach ($tool['settings'] ?? [] as $key => $value) {
            if ($key === 'allowed_skills') {
                $grants = array_merge($grants, (array) $value);
            }
        }
    }

    expect($grants)->toContain('social-poster');
});

it('pre-approves the reads and drafts and nothing that publishes', function () {
    foreach (socialAgentTemplate()['tools'] ?? [] as $tool) {
        foreach ($tool['operations'] as $operation) {
            $declared = operationsOf($tool['tool_class'])[$operation['name']];

            // The template may only pre-approve what the tool itself
            // considers free, so an agent cannot be handed a licence the
            // operator never gave it.
            expect($operation['auto_approve'])->toBe(!$declared->requiresApprovalByDefault);
        }
    }
});

it('names the plugin tools the skill may use', function () {
    $skill = socialSkill();

    $allowed = preg_split('/\s+/', trim((string) ($skill['frontmatter']['allowed-tools'] ?? ''))) ?: [];

    $wireNames = [];
    foreach ((new Spora\Plugins\Social\SocialPlugin())->tools() as $class) {
        $attribute = (new ReflectionClass($class))->getAttributes(Spora\Tools\Attributes\Tool::class)[0] ?? null;
        if ($attribute !== null) {
            $wireNames[] = $attribute->newInstance()->name;
        }
    }

    sort($allowed);
    sort($wireNames);

    expect($allowed)->toBe($wireNames);
});
