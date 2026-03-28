<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class GoogleMaps extends SkillBase
{
    public function getName(): string
    {
        return 'google_maps';
    }

    public function getDescription(): string
    {
        return 'Validate addresses and compute driving routes using Google Maps';
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
        $apiKey = $this->params['api_key'] ?? '';
        $lookupToolName = $this->params['lookup_tool_name'] ?? 'lookup_address';
        $routeToolName = $this->params['route_tool_name'] ?? 'compute_route';

        // lookup_address tool — DataMap with Google Geocoding API
        $lookupDef = [
            'function' => $lookupToolName,
            'purpose' => 'Look up and validate an address using Google Maps Geocoding',
            'argument' => [
                'type' => 'object',
                'properties' => [
                    'address' => [
                        'type' => 'string',
                        'description' => 'The address to look up',
                    ],
                    'bias_lat' => [
                        'type' => 'number',
                        'description' => 'Latitude to bias results toward (optional)',
                    ],
                    'bias_lng' => [
                        'type' => 'number',
                        'description' => 'Longitude to bias results toward (optional)',
                    ],
                ],
                'required' => ['address'],
            ],
            'data_map' => [
                'webhooks' => [
                    [
                        'url' => 'https://maps.googleapis.com/maps/api/geocode/json?address=${enc:args.address}&key=' . $apiKey,
                        'method' => 'GET',
                        'output' => [
                            'response' => 'Address found: ${results[0].formatted_address}. '
                                . 'Latitude: ${results[0].geometry.location.lat}, '
                                . 'Longitude: ${results[0].geometry.location.lng}',
                            'action' => [['say_it' => true]],
                        ],
                        'error_output' => [
                            'response' => 'Unable to look up the address. Please check the address and try again.',
                            'action' => [['say_it' => true]],
                        ],
                    ],
                ],
            ],
        ];

        // compute_route tool — DataMap with Google Routes API
        $routeDef = [
            'function' => $routeToolName,
            'purpose' => 'Compute a driving route between two locations using Google Maps',
            'argument' => [
                'type' => 'object',
                'properties' => [
                    'origin_lat' => [
                        'type' => 'number',
                        'description' => 'Latitude of the origin',
                    ],
                    'origin_lng' => [
                        'type' => 'number',
                        'description' => 'Longitude of the origin',
                    ],
                    'dest_lat' => [
                        'type' => 'number',
                        'description' => 'Latitude of the destination',
                    ],
                    'dest_lng' => [
                        'type' => 'number',
                        'description' => 'Longitude of the destination',
                    ],
                ],
                'required' => ['origin_lat', 'origin_lng', 'dest_lat', 'dest_lng'],
            ],
            'data_map' => [
                'webhooks' => [
                    [
                        'url' => 'https://routes.googleapis.com/directions/v2:computeRoutes',
                        'method' => 'POST',
                        'headers' => [
                            'X-Goog-Api-Key' => $apiKey,
                            'X-Goog-FieldMask' => 'routes.duration,routes.distanceMeters,routes.legs',
                            'Content-Type' => 'application/json',
                        ],
                        'body' => [
                            'origin' => [
                                'location' => [
                                    'latLng' => [
                                        'latitude' => '${args.origin_lat}',
                                        'longitude' => '${args.origin_lng}',
                                    ],
                                ],
                            ],
                            'destination' => [
                                'location' => [
                                    'latLng' => [
                                        'latitude' => '${args.dest_lat}',
                                        'longitude' => '${args.dest_lng}',
                                    ],
                                ],
                            ],
                            'travelMode' => 'DRIVE',
                        ],
                        'output' => [
                            'response' => 'Route computed. Distance: ${routes[0].distanceMeters} meters, '
                                . 'Duration: ${routes[0].duration}',
                            'action' => [['say_it' => true]],
                        ],
                        'error_output' => [
                            'response' => 'Unable to compute route between the specified locations.',
                            'action' => [['say_it' => true]],
                        ],
                    ],
                ],
            ],
        ];

        if (!empty($this->swaigFields)) {
            $lookupDef = array_merge($lookupDef, $this->swaigFields);
            $routeDef = array_merge($routeDef, $this->swaigFields);
        }

        $this->agent->registerSwaigFunction($lookupDef);
        $this->agent->registerSwaigFunction($routeDef);
    }

    public function getHints(): array
    {
        return ['address', 'location', 'route', 'directions', 'miles', 'distance'];
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [
            [
                'title' => 'Google Maps',
                'body' => 'You can look up addresses and compute driving routes.',
                'bullets' => [
                    'Use lookup_address to validate and geocode an address.',
                    'Use compute_route to get driving distance and duration between two coordinates.',
                    'First look up addresses to get coordinates, then compute routes between them.',
                ],
            ],
        ];
    }
}
