<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

class WeatherApi extends SkillBase
{
    /**
     * Initialize the skill with configuration parameters.
     *
     * Mirrors Python `WeatherApiSkill.__init__` (skill.py:78): after the base
     * constructor runs, validate the api_key and temperature_unit up front so a
     * misconfigured skill fails at construction.
     *
     * @param array<string,mixed> $params
     */
    public function __construct(\SignalWire\Agent\AgentInterface $agent, array $params = [])
    {
        parent::__construct($agent, $params);
        $this->validateConfig();
    }

    private function validateConfig(): void
    {
        $apiKey = $this->params['api_key'] ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'api_key parameter is required and must be a non-empty string'
            );
        }
        $unit = $this->params['temperature_unit'] ?? 'fahrenheit';
        if ($unit !== 'fahrenheit' && $unit !== 'celsius') {
            throw new \InvalidArgumentException(
                "temperature_unit must be either 'fahrenheit' or 'celsius'"
            );
        }
    }

    public function getName(): string
    {
        return 'weather_api';
    }

    public function getDescription(): string
    {
        return 'Get current weather information from WeatherAPI.com';
    }

    /**
     * Parameter schema for the weather API skill.
     *
     * Mirrors Python `WeatherApiSkill.get_parameter_schema` (skill.py:49):
     * merges the base schema with api_key + tool_name + temperature_unit.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'api_key' => [
                'type' => 'string',
                'description' => 'WeatherAPI.com API key',
                'required' => true,
                'hidden' => true,
                'env_var' => 'WEATHER_API_KEY',
            ],
            'tool_name' => [
                'type' => 'string',
                'description' => 'Custom name for the weather tool',
                'default' => 'get_weather',
                'required' => false,
            ],
            'temperature_unit' => [
                'type' => 'string',
                'description' => 'Temperature unit to display',
                'default' => 'fahrenheit',
                'required' => false,
                'enum' => ['fahrenheit', 'celsius'],
            ],
        ]);
        return $schema;
    }

    public function setup(): bool
    {
        if (empty($this->params['api_key'])) {
            return false;
        }

        return true;
    }

    public function registerTools(): void
    {
        foreach ($this->getTools() as $funcDef) {
            if (!empty($this->swaigFields)) {
                $funcDef = array_merge($funcDef, $this->swaigFields);
            }
            $this->agent->registerSwaigFunction($funcDef);
        }
    }

    /**
     * Generate the SWAIG tool(s) with the DataMap webhook.
     *
     * Mirrors Python `WeatherApiSkill.get_tools` (skill.py:132).
     *
     * @return list<array<string,mixed>>
     */
    public function getTools(): array
    {
        $toolName = $this->getToolName('get_weather');
        $apiKey = $this->paramString('api_key');
        $unit = $this->params['temperature_unit'] ?? 'fahrenheit';

        if ($unit === 'celsius') {
            $tempField = '${current.temp_c}';
            $feelsField = '${current.feelslike_c}';
            $unitLabel = 'C';
        } else {
            $tempField = '${current.temp_f}';
            $feelsField = '${current.feelslike_f}';
            $unitLabel = 'F';
        }

        $outputResponse = 'Weather in ${location.name}, ${location.region}: '
            . 'Temperature: ' . $tempField . '°' . $unitLabel . ', '
            . 'Feels like: ' . $feelsField . '°' . $unitLabel . ', '
            . 'Condition: ${current.condition.text}, '
            . 'Humidity: ${current.humidity}%, '
            . 'Wind: ${current.wind_mph} mph ${current.wind_dir}';

        $funcDef = [
            'function' => $toolName,
            'purpose' => 'Get current weather information for any location',
            'argument' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The location to get weather for (city name, zip code, or coordinates)',
                    ],
                ],
                'required' => ['location'],
            ],
            'data_map' => [
                'webhooks' => [
                    [
                        'url' => 'https://api.weatherapi.com/v1/current.json?key=' . $apiKey . '&q=${lc:enc:args.location}&aqi=no',
                        'method' => 'GET',
                        'output' => [
                            'response' => $outputResponse,
                            'action' => [['say_it' => true]],
                        ],
                        'error_output' => [
                            'response' => 'Unable to retrieve weather information for the requested location.',
                            'action' => [['say_it' => true]],
                        ],
                    ],
                ],
            ],
        ];

        return [$funcDef];
    }
}
