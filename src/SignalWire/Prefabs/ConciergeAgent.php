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
     * @param array<string,mixed> $venueInfo Venue information: venue_name (required), services, amenities,
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
        $venueName = $venueInfo['venue_name'] ?? '';
        $this->venueName = is_string($venueName) ? $venueName : '';

        $services = $venueInfo['services'] ?? [];
        $this->services = [];
        if (is_array($services)) {
            foreach ($services as $service) {
                if (is_string($service)) {
                    $this->services[] = $service;
                }
            }
        }

        $amenities = $venueInfo['amenities'] ?? [];
        $this->amenities = [];
        if (is_array($amenities)) {
            foreach ($amenities as $amenityName => $info) {
                if (!is_string($amenityName) || !is_array($info)) {
                    continue;
                }
                $entry = [];
                if (isset($info['hours']) && is_string($info['hours'])) {
                    $entry['hours'] = $info['hours'];
                }
                if (isset($info['location']) && is_string($info['location'])) {
                    $entry['location'] = $info['location'];
                }
                $this->amenities[$amenityName] = $entry;
            }
        }

        $hours = $venueInfo['hours_of_operation'] ?? [];
        $this->hoursOfOperation = [];
        if (is_array($hours)) {
            foreach ($hours as $day => $value) {
                if (is_string($day) && is_string($value)) {
                    $this->hoursOfOperation[$day] = $value;
                }
            }
        }

        $instructions = $venueInfo['special_instructions'] ?? [];
        $this->specialInstructions = [];
        if (is_array($instructions)) {
            foreach ($instructions as $instruction) {
                if (is_string($instruction)) {
                    $this->specialInstructions[] = $instruction;
                }
            }
        }

        $welcomeMessage = $venueInfo['welcome_message'] ?? null;
        $this->welcomeMessage = is_string($welcomeMessage) ? $welcomeMessage : null;

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

        // Tool: check_availability — dispatches to the named handler method.
        $this->defineTool(
            name: 'check_availability',
            description: 'Check availability for a service on a specific date and time',
            parameters: [
                'service' => ['type' => 'string', 'description' => 'The service to check availability for'],
                'date'    => ['type' => 'string', 'description' => 'The date to check (optional)'],
                'time'    => ['type' => 'string', 'description' => 'The time to check (optional)'],
            ],
            handler: fn (array $args, array $rawData): FunctionResult => $this->checkAvailability($args, $rawData),
        );

        // Tool: get_directions — dispatches to the named handler method.
        $this->defineTool(
            name: 'get_directions',
            description: 'Get directions to a specific location or amenity',
            parameters: [
                'location' => ['type' => 'string', 'description' => 'The location or amenity to get directions to'],
            ],
            handler: fn (array $args, array $rawData): FunctionResult => $this->getDirections($args, $rawData),
        );
    }

    /**
     * Check availability for a service on a specific date and time.
     *
     * Mirrors Python `ConciergeAgent.check_availability`. In a real deployment
     * this would connect to a booking system; here it validates the requested
     * service against the configured list.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $rawData
     */
    public function checkAvailability(array $args, array $rawData): FunctionResult
    {
        $service = strtolower(is_string($args['service'] ?? null) ? $args['service'] : '');
        $date    = is_string($args['date'] ?? null) ? $args['date'] : '';
        $time    = is_string($args['time'] ?? null) ? $args['time'] : '';

        $lowerServices = array_map('strtolower', $this->services);
        if (in_array($service, $lowerServices, true)) {
            return new FunctionResult(
                "Yes, {$service} is available on {$date} at {$time}. Would you like to make a reservation?"
            );
        }

        $available = implode(', ', $this->services);
        return new FunctionResult(
            "I'm sorry, we don't offer {$service} at {$this->venueName}. "
            . "Our available services are: {$available}."
        );
    }

    /**
     * Provide directions to a specific location or amenity.
     *
     * Mirrors Python `ConciergeAgent.get_directions`.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $rawData
     */
    public function getDirections(array $args, array $rawData): FunctionResult
    {
        $location = strtolower(is_string($args['location'] ?? null) ? $args['location'] : '');

        if (isset($this->amenities[$location]) && isset($this->amenities[$location]['location'])) {
            $amenityLocation = $this->amenities[$location]['location'];
            return new FunctionResult(
                "The {$location} is located at {$amenityLocation}. "
                . "From the main entrance, follow the signs to {$amenityLocation}."
            );
        }

        return new FunctionResult(
            "I don't have specific directions to {$location}. "
            . 'You can ask our staff at the front desk for assistance.'
        );
    }

    /**
     * Process the interaction summary.
     *
     * Mirrors Python `ConciergeAgent.on_summary`: logs the structured or
     * free-form summary. Subclasses can override to persist the interaction.
     *
     * @param array<string,mixed>|string|null $summary
     * @param array<string,mixed>|null        $rawData
     */
    public function onSummary(array|string|null $summary, ?array $rawData = null): void
    {
        if ($summary === null || $summary === '') {
            return;
        }
        if (is_array($summary)) {
            $this->logger->info('Concierge interaction summary: ' . json_encode($summary, JSON_PRETTY_PRINT));
        } else {
            $this->logger->info("Concierge interaction summary: {$summary}");
        }
    }

    /** The venue name. */
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
     * @return array<string, array{hours?: string, location?: string}>
     */
    public function getAmenities(): array
    {
        return $this->amenities;
    }
}
