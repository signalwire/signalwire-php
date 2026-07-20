<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class Datetime extends SkillBase
{
    /** The name. */
    public function getName(): string
    {
        return 'datetime';
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Get current date, time, and timezone information';
    }

    public function setup(): bool
    {
        return true;
    }

    /**
     * Speech-recognition hints for this skill.
     *
     * Mirrors Python `DateTimeSkill.get_hints` (skill.py:98): the datetime
     * skill provides no hints.
     *
     * @return list<string>
     */
    public function getHints(): array
    {
        return [];
    }

    /**
     * Parameter schema for the datetime skill.
     *
     * Mirrors Python `DateTimeSkill.get_parameter_schema` (skill.py:119): the
     * datetime skill has no custom parameters and inherits only the base
     * schema.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        return parent::getParameterSchema();
    }

    public function registerTools(): void
    {
        $this->defineTool(
            'get_current_time',
            'Get the current time, optionally in a specific timezone',
            [
                'timezone' => [
                    'type' => 'string',
                    'description' => 'Timezone name (e.g., America/New_York, Europe/London). Defaults to UTC.',
                ],
            ],
            function (array $args, array $rawData): FunctionResult {
                $result = new FunctionResult();
                $timezone = $args['timezone'] ?? 'UTC';

                try {
                    $tz = new \DateTimeZone($timezone);
                    $now = new \DateTime('now', $tz);
                    $result->setResponse(
                        'The current time in ' . $timezone . ' is ' . $now->format('H:i:s T')
                    );
                } catch (\Exception $e) {
                    $result->setResponse('Invalid timezone: ' . $timezone);
                }

                return $result;
            }
        );

        $this->defineTool(
            'get_current_date',
            'Get the current date',
            [
                'timezone' => [
                    'type' => 'string',
                    'description' => 'Timezone name (e.g., America/New_York, Europe/London). Defaults to UTC.',
                ],
            ],
            function (array $args, array $rawData): FunctionResult {
                $result = new FunctionResult();
                $timezone = $args['timezone'] ?? 'UTC';

                try {
                    $tz = new \DateTimeZone($timezone);
                    $now = new \DateTime('now', $tz);
                    $result->setResponse(
                        'The current date in ' . $timezone . ' is ' . $now->format('Y-m-d (l, F j, Y)')
                    );
                } catch (\Exception $e) {
                    $result->setResponse('Invalid timezone: ' . $timezone);
                }

                return $result;
            }
        );
    }

    /**
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [
            [
                'title' => 'Date and Time Information',
                'body' => 'You have access to date and time tools.',
                'bullets' => [
                    'Use get_current_time to retrieve the current time in any timezone.',
                    'Use get_current_date to retrieve the current date in any timezone.',
                    'Default timezone is UTC if none is specified.',
                ],
            ],
        ];
    }
}
