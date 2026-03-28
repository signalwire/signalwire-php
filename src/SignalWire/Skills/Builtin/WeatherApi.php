<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class WeatherApi extends SkillBase
{
    public function getName(): string
    {
        return 'weather_api';
    }

    public function getDescription(): string
    {
        return 'Get current weather information from WeatherAPI.com';
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
        $toolName = $this->getToolName('get_weather');
        $apiKey = $this->params['api_key'] ?? '';
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

        if (!empty($this->swaigFields)) {
            $funcDef = array_merge($funcDef, $this->swaigFields);
        }

        $this->agent->registerSwaigFunction($funcDef);
    }
}
