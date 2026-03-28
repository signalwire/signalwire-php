<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class ClaudeSkills extends SkillBase
{
    public function getName(): string
    {
        return 'claude_skills';
    }

    public function getDescription(): string
    {
        return 'Load Claude SKILL.md files as agent tools';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        if (empty($this->params['skills_path'])) {
            return false;
        }

        return true;
    }

    public function registerTools(): void
    {
        $skillsPath = $this->params['skills_path'] ?? '';
        $toolPrefix = $this->params['tool_prefix'] ?? 'claude_';
        $include = $this->params['include'] ?? [];
        $exclude = $this->params['exclude'] ?? [];
        $skillDescriptions = $this->params['skill_descriptions'] ?? [];
        $responsePrefix = $this->params['response_prefix'] ?? '';
        $responsePostfix = $this->params['response_postfix'] ?? '';

        // Discover skill files from path
        // In production, this would scan the directory for .md files with YAML frontmatter
        // For stub implementation, register a placeholder tool
        $toolName = $toolPrefix . 'skill';

        $this->defineTool(
            $toolName,
            'Execute a Claude skill from ' . $skillsPath,
            [
                'arguments' => [
                    'type' => 'string',
                    'description' => 'Arguments to pass to the skill',
                    'required' => true,
                ],
                'section' => [
                    'type' => 'string',
                    'description' => 'Optional section of the skill to invoke',
                ],
            ],
            function (array $args, array $rawData) use ($skillsPath, $responsePrefix, $responsePostfix): FunctionResult {
                $result = new FunctionResult();
                $arguments = $args['arguments'] ?? '';
                $section = $args['section'] ?? '';

                // Stub: in production, this would read and execute SKILL.md files
                $response = '';

                if ($responsePrefix !== '') {
                    $response .= $responsePrefix . ' ';
                }

                $response .= 'Claude skill execution from "' . $skillsPath . '"';

                if ($section !== '') {
                    $response .= ' (section: ' . $section . ')';
                }

                $response .= ' with arguments: ' . $arguments . '. ';
                $response .= 'In production, this would parse SKILL.md files with YAML frontmatter and execute them.';

                if ($responsePostfix !== '') {
                    $response .= ' ' . $responsePostfix;
                }

                $result->setResponse($response);

                return $result;
            }
        );
    }

    public function getHints(): array
    {
        return ['claude', 'skill'];
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        $skillsPath = $this->params['skills_path'] ?? '';

        return [
            [
                'title' => 'Claude Skills',
                'body' => 'You have access to Claude skills loaded from ' . $skillsPath . '.',
                'bullets' => [
                    'Use claude skill tools to execute specialized tasks.',
                    'Pass arguments as a string describing what you need.',
                    'Optionally specify a section to target a specific part of the skill.',
                ],
            ],
        ];
    }
}
