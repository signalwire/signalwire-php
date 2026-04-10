<?php

declare(strict_types=1);

namespace SignalWire\Prefabs;

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

class ConciergeAgent extends AgentBase
{
    protected string $venueName;

    /** @var list<string> */
    protected array $services;

    /** @var array<string, array{hours?: string, location?: string}> */
    protected array $amenities;

    /** @var array<string, string> */
    protected array $hoursOfOperation;

    /** @var list<string> */
    protected array $specialInstructions;

    protected ?string $welcomeMessage;

    /**
     * @param string $name Agent name
     * @param array $venueInfo Venue information: venue_name (required), services, amenities,
     *                         hours_of_operation, special_instructions, welcome_message
     * @param string $route
     * @param string|null $host
     * @param int|null $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param bool $autoAnswer
     * @param bool $recordCall
     * @param bool $usePom
     */
    public function __construct(
        string $name,
        array $venueInfo,
        string $route = '/concierge',
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        bool $autoAnswer = true,
        bool $recordCall = false,
        bool $usePom = true,
    ) {
        $this->venueName           = $venueInfo['venue_name'];
        $this->services            = $venueInfo['services'] ?? [];
        $this->amenities           = $venueInfo['amenities'] ?? [];
        $this->hoursOfOperation    = $venueInfo['hours_of_operation'] ?? [];
        $this->specialInstructions = $venueInfo['special_instructions'] ?? [];
        $this->welcomeMessage      = $venueInfo['welcome_message'] ?? null;

        $name = $name !== '' ? $name : 'concierge';

        parent::__construct(
            name: $name,
            route: $route,
            host: $host,
            port: $port,
            basicAuthUser: $basicAuthUser,
            basicAuthPassword: $basicAuthPassword,
            autoAnswer: $autoAnswer,
            recordCall: $recordCall,
            usePom: $usePom,
        );

        $this->usePom = true;

        $welcome = $this->welcomeMessage
            ?? "Welcome to {$this->venueName}. How can I assist you today?";

        // Global data
        $this->setGlobalData([
            'venue_name' => $this->venueName,
            'services'   => $this->services,
            'amenities'  => $this->amenities,
        ]);

        // Role section
        $this->promptAddSection(
            'Concierge Role',
            "You are the virtual concierge for {$this->venueName}. {$welcome}",
            [
                'Welcome users and explain available services',
                'Answer questions about amenities, hours, and directions',
                'Help with bookings and reservations',
                'Provide personalized recommendations',
            ],
        );

        // Services section
        if (!empty($this->services)) {
            $this->promptAddSection('Available Services', '', $this->services);
        }

        // Amenities section
        if (!empty($this->amenities)) {
            $amenityBullets = [];
            foreach ($this->amenities as $amenityName => $info) {
                $desc = $amenityName;
                if (!empty($info['hours'])) {
                    $desc .= " - Hours: {$info['hours']}";
                }
                if (!empty($info['location'])) {
                    $desc .= " - Location: {$info['location']}";
                }
                $amenityBullets[] = $desc;
            }
            $this->promptAddSection('Amenities', '', $amenityBullets);
        }

        // Hours of operation section
        if (!empty($this->hoursOfOperation)) {
            $hourBullets = [];
            foreach ($this->hoursOfOperation as $day => $hours) {
                $hourBullets[] = "{$day}: {$hours}";
            }
            $this->promptAddSection('Hours of Operation', '', $hourBullets);
        }

        // Special instructions section
        if (!empty($this->specialInstructions)) {
            $this->promptAddSection('Special Instructions', '', $this->specialInstructions);
        }

        // Tool: check_availability
        $capturedVenueName = $this->venueName;
        $this->defineTool(
            name: 'check_availability',
            description: 'Check availability for a service or amenity',
            parameters: [
                'service' => ['type' => 'string', 'description' => 'Service or amenity to check'],
                'date'    => ['type' => 'string', 'description' => 'Date to check (optional)'],
            ],
            handler: function (array $args, array $rawData) use ($capturedVenueName): FunctionResult {
                $service = $args['service'] ?? '';
                $date    = $args['date'] ?? '';
                $response = "Checking availability for {$service} at {$capturedVenueName}";
                if ($date !== '') {
                    $response .= " on {$date}";
                }
                return new FunctionResult($response);
            },
        );

        // Tool: get_directions
        $capturedAmenities = $this->amenities;
        $this->defineTool(
            name: 'get_directions',
            description: 'Get directions to a service or amenity within the venue',
            parameters: [
                'destination' => ['type' => 'string', 'description' => 'The amenity or area to get directions to'],
            ],
            handler: function (array $args, array $rawData) use ($capturedVenueName, $capturedAmenities): FunctionResult {
                $destination = $args['destination'] ?? '';
                $destinationLower = strtolower($destination);

                foreach ($capturedAmenities as $amenityName => $info) {
                    if (strtolower($amenityName) === $destinationLower) {
                        $location = $info['location'] ?? 'location not specified';
                        return new FunctionResult(
                            "The {$amenityName} at {$capturedVenueName} is located at: {$location}",
                        );
                    }
                }

                return new FunctionResult(
                    "Directions to {$destination} at {$capturedVenueName}: please ask the front desk for assistance.",
                );
            },
        );
    }

    public function getVenueName(): string
    {
        return $this->venueName;
    }

    /**
     * @return list<string>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * @return array<string, array>
     */
    public function getAmenities(): array
    {
        return $this->amenities;
    }
}
