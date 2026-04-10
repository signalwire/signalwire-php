<?php
/**
 * Concierge Agent Example
 *
 * Uses the ConciergeAgent prefab for providing virtual concierge
 * services with information about amenities and services.
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\ConciergeAgent;

$agent = new ConciergeAgent(
    name:  'concierge',
    venueInfo: [
        'venue_name' => 'Oceanview Resort',
        'services'   => [
            'room service', 'spa bookings', 'restaurant reservations',
            'activity bookings', 'airport shuttle', 'valet parking',
        ],
        'amenities'  => [
            'infinity pool' => [
                'hours'       => '7:00 AM - 10:00 PM',
                'location'    => 'Main Level, Ocean View',
                'description' => 'Heated infinity pool overlooking the ocean with poolside service.',
            ],
            'spa' => [
                'hours'       => '9:00 AM - 8:00 PM',
                'location'    => 'Lower Level, East Wing',
                'description' => 'Full-service luxury spa offering massages, facials, and body treatments.',
                'reservation' => 'Required',
            ],
            'fitness center' => [
                'hours'       => '24 hours',
                'location'    => '2nd Floor, North Wing',
                'description' => 'State-of-the-art fitness center with cardio equipment and weights.',
            ],
            'beach access' => [
                'hours'    => 'Dawn to Dusk',
                'location' => 'Southern Pathway',
                'description' => 'Private beach access with complimentary chairs and umbrellas.',
            ],
        ],
        'hours_of_operation' => [
            'check-in'     => '3:00 PM',
            'check-out'    => '11:00 AM',
            'front desk'   => '24 hours',
            'concierge'    => '7:00 AM - 11:00 PM',
            'room service' => '24 hours',
        ],
        'special_instructions' => [
            'Always greet guests by name when possible.',
            'Offer to make reservations for guests at local attractions.',
            'Provide weather updates when discussing outdoor activities.',
        ],
        'welcome_message' => 'Welcome to Oceanview Resort. I\'m your virtual concierge. How may I help you today?',
    ],
    route: '/concierge',
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

echo "Starting Concierge Agent for Oceanview Resort\n";
echo "Available at: http://localhost:3000/concierge\n";

$agent->run();
