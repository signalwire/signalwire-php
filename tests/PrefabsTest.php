<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use JetBrains\PhpStorm\ArrayShape;
use PHPUnit\Framework\TestCase;
use SignalWire\Prefabs\InfoGathererAgent;
use SignalWire\Prefabs\SurveyAgent;
use SignalWire\Prefabs\ReceptionistAgent;
use SignalWire\Prefabs\FAQBotAgent;
use SignalWire\Prefabs\ConciergeAgent;
use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Logging\Logger;
use SignalWire\SWML\Schema;

class PrefabsTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
    }

    #[ArrayShape(['basicAuthUser' => "string", 'basicAuthPassword' => "string"])]
    private function baseOptions(): array
    {
        return [
            'basicAuthUser'     => 'testuser',
            'basicAuthPassword' => 'testpass',
        ];
    }

    // ==================================================================
    //  InfoGathererAgent
    // ==================================================================

    public function testInfoGathererConstruction(): void
    {
        $agent = new InfoGathererAgent(
            name: 'info_gatherer',
            questions: [
                ['key_name' => 'full_name', 'question_text' => 'What is your full name?'],
                ['key_name' => 'email',     'question_text' => 'What is your email?', 'confirm' => true],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertSame('info_gatherer', $agent->getName());
        $this->assertSame('/info_gatherer', $agent->getRoute());
        $this->assertTrue($agent->promptHasSection('Information Gathering'));
        $this->assertCount(2, $agent->getQuestions());
    }

    public function testInfoGathererHasExpectedTools(): void
    {
        $agent = new InfoGathererAgent(
            name: 'info_gatherer',
            questions: [
                ['key_name' => 'name', 'question_text' => 'What is your name?'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('start_questions', [], []);
        $this->assertInstanceOf(FunctionResult::class, $result);

        $result2 = $agent->onFunctionCall('submit_answer', ['answer' => 'John'], []);
        $this->assertInstanceOf(FunctionResult::class, $result2);
    }

    public function testInfoGathererStartQuestionsReturnsFirstQuestion(): void
    {
        $agent = new InfoGathererAgent(
            name: 'info_gatherer',
            questions: [
                ['key_name' => 'name', 'question_text' => 'What is your name?'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('start_questions', [], []);
        $this->assertInstanceOf(FunctionResult::class, $result);
        $arr = $result->toArray();
        $this->assertStringContainsString('What is your name?', $arr['response']);
    }

    public function testInfoGathererSubmitAnswerRecords(): void
    {
        $agent = new InfoGathererAgent(
            name: 'info_gatherer',
            questions: [
                ['key_name' => 'name', 'question_text' => 'What is your name?'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('submit_answer', ['answer' => 'Alice'], []);
        $this->assertInstanceOf(FunctionResult::class, $result);
        $arr = $result->toArray();
        $this->assertStringContainsString('Alice', $arr['response']);
    }

    public function testInfoGathererSwmlRendering(): void
    {
        $agent = new InfoGathererAgent(
            name: 'info_gatherer',
            questions: [
                ['key_name' => 'name', 'question_text' => 'Your name?'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $swml = $agent->renderSwml();
        $this->assertSame('1.0.0', $swml['version']);
        $this->assertArrayHasKey('main', $swml['sections']);

        $aiVerbs = array_filter(
            $swml['sections']['main'],
            fn(array $v) => array_key_first($v) === 'ai',
        );
        $this->assertNotEmpty($aiVerbs);
    }

    public function testInfoGathererInheritsAgentBase(): void
    {
        $agent = new InfoGathererAgent(
            name: 'info_gatherer',
            questions: [
                ['key_name' => 'n', 'question_text' => 'Name?'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertInstanceOf(AgentBase::class, $agent);
    }

    // ==================================================================
    //  SurveyAgent
    // ==================================================================

    public function testSurveyConstruction(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Rate our service', 'type' => 'rating', 'scale' => 5, 'required' => true],
                ['id' => 'q2', 'text' => 'Any comments?',    'type' => 'open_ended', 'required' => false],
            ],
            surveyName: 'Satisfaction Survey',
            introduction: 'Welcome to our survey.',
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertSame('survey', $agent->getName());
        $this->assertSame('/survey', $agent->getRoute());
        $this->assertTrue($agent->promptHasSection('Survey Introduction'));
        $this->assertTrue($agent->promptHasSection('Survey Questions'));
        $this->assertCount(2, $agent->getSurveyQuestions());
    }

    public function testSurveyHasExpectedTools(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Rate us', 'type' => 'rating', 'scale' => 5, 'required' => true],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => '4',
        ], []);
        $this->assertInstanceOf(FunctionResult::class, $result);

        $result2 = $agent->onFunctionCall('log_response', [
            'question_id' => 'q1',
            'answer'      => '4',
        ], []);
        $this->assertInstanceOf(FunctionResult::class, $result2);
    }

    public function testSurveyValidatesRating(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Rate us', 'type' => 'rating', 'scale' => 5, 'required' => true],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        // Valid rating
        $result = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => '3',
        ], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Valid rating', $arr['response']);

        // Invalid rating
        $result2 = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => '7',
        ], []);
        $arr2 = $result2->toArray();
        $this->assertStringContainsString('Invalid rating', $arr2['response']);
    }

    public function testSurveyValidatesMultipleChoice(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Pick one', 'type' => 'multiple_choice', 'choices' => ['Red', 'Blue', 'Green']],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => 'Blue',
        ], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Valid choice', $arr['response']);

        $result2 = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => 'Purple',
        ], []);
        $arr2 = $result2->toArray();
        $this->assertStringContainsString('Invalid choice', $arr2['response']);
    }

    public function testSurveyValidatesYesNo(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Do you agree?', 'type' => 'yes_no'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => 'yes',
        ], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Valid response', $arr['response']);

        $result2 = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => 'maybe',
        ], []);
        $arr2 = $result2->toArray();
        $this->assertStringContainsString('yes or no', $arr2['response']);
    }

    public function testSurveyValidatesOpenEnded(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Comments?', 'type' => 'open_ended'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => 'Great service!',
        ], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Response accepted', $arr['response']);

        $result2 = $agent->onFunctionCall('validate_response', [
            'question_id' => 'q1',
            'answer'      => '',
        ], []);
        $arr2 = $result2->toArray();
        $this->assertStringContainsString('non-empty', $arr2['response']);
    }

    public function testSurveyLogResponse(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Rate us', 'type' => 'rating', 'scale' => 5],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('log_response', [
            'question_id' => 'q1',
            'answer'      => '5',
        ], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('q1', $arr['response']);
        $this->assertStringContainsString('5', $arr['response']);
    }

    public function testSurveySwmlRendering(): void
    {
        $agent = new SurveyAgent(
            name: 'survey',
            questions: [
                ['id' => 'q1', 'text' => 'Q?', 'type' => 'rating', 'scale' => 5, 'required' => true],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $swml = $agent->renderSwml();
        $this->assertSame('1.0.0', $swml['version']);
    }

    // ==================================================================
    //  ReceptionistAgent
    // ==================================================================

    public function testReceptionistConstruction(): void
    {
        $agent = new ReceptionistAgent(
            name: 'receptionist',
            departments: [
                ['name' => 'sales',   'description' => 'For purchasing',    'number' => '+15551235555'],
                ['name' => 'support', 'description' => 'For tech help',     'number' => '+15551236666'],
            ],
            greeting: 'Welcome to Acme Corp!',
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertSame('receptionist', $agent->getName());
        $this->assertSame('/receptionist', $agent->getRoute());
        $this->assertTrue($agent->promptHasSection('Receptionist Role'));
        $this->assertCount(2, $agent->getDepartments());
    }

    public function testReceptionistHasExpectedTools(): void
    {
        $agent = new ReceptionistAgent(
            name: 'receptionist',
            departments: [
                ['name' => 'sales', 'description' => 'Sales dept', 'number' => '+15551235555'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('collect_caller_info', [
            'caller_name' => 'Alice',
            'reason'      => 'Product inquiry',
        ], []);
        $this->assertInstanceOf(FunctionResult::class, $result);

        $result2 = $agent->onFunctionCall('transfer_call', [
            'department' => 'sales',
        ], []);
        $this->assertInstanceOf(FunctionResult::class, $result2);
    }

    public function testReceptionistTransferFound(): void
    {
        $agent = new ReceptionistAgent(
            name: 'receptionist',
            departments: [
                ['name' => 'sales', 'description' => 'Sales dept', 'number' => '+15551235555'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('transfer_call', ['department' => 'sales'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Transferring to sales', $arr['response']);
    }

    public function testReceptionistTransferNotFound(): void
    {
        $agent = new ReceptionistAgent(
            name: 'receptionist',
            departments: [
                ['name' => 'sales', 'description' => 'Sales dept', 'number' => '+15551235555'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('transfer_call', ['department' => 'billing'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('not found', $arr['response']);
    }

    public function testReceptionistSwmlTransferType(): void
    {
        $agent = new ReceptionistAgent(
            name: 'receptionist',
            departments: [
                ['name' => 'support', 'description' => 'Support', 'transfer_type' => 'swml', 'swml_url' => 'https://example.com/swml'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('transfer_call', ['department' => 'support'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('support', $arr['response']);
        // Should have a transfer_uri action
        $this->assertArrayHasKey('action', $arr);
    }

    public function testReceptionistPhoneTransferType(): void
    {
        $agent = new ReceptionistAgent(
            name: 'receptionist',
            departments: [
                ['name' => 'sales', 'description' => 'Sales', 'transfer_type' => 'phone', 'number' => '+15551234567'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('transfer_call', ['department' => 'sales'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Transferring to sales', $arr['response']);
        // Should have a SWML connect action
        $this->assertArrayHasKey('action', $arr);
    }

    // ==================================================================
    //  FAQBotAgent
    // ==================================================================

    public function testFAQBotConstruction(): void
    {
        $agent = new FAQBotAgent(
            name: 'faq_bot',
            faqs: [
                ['question' => 'What is SignalWire?', 'answer' => 'A cloud comms platform.'],
                ['question' => 'How much?',           'answer' => 'Pay-as-you-go pricing.'],
            ],
            suggestRelated: true,
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertSame('faq_bot', $agent->getName());
        $this->assertSame('/faq', $agent->getRoute());
        $this->assertTrue($agent->promptHasSection('Personality'));
        $this->assertTrue($agent->promptHasSection('FAQ Knowledge Base'));
        $this->assertTrue($agent->promptHasSection('Related Questions'));
        $this->assertCount(2, $agent->getFaqs());
    }

    public function testFAQBotNoRelatedSection(): void
    {
        $agent = new FAQBotAgent(
            name: 'faq_bot',
            faqs: [
                ['question' => 'Q?', 'answer' => 'A.'],
            ],
            suggestRelated: false,
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertFalse($agent->promptHasSection('Related Questions'));
    }

    public function testFAQBotHasExpectedTools(): void
    {
        $agent = new FAQBotAgent(
            name: 'faq_bot',
            faqs: [
                ['question' => 'What is SignalWire?', 'answer' => 'Cloud comms platform.'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('search_faqs', ['query' => 'signalwire'], []);
        $this->assertInstanceOf(FunctionResult::class, $result);
    }

    public function testFAQBotSearchFindsMatch(): void
    {
        $agent = new FAQBotAgent(
            name: 'faq_bot',
            faqs: [
                ['question' => 'What is SignalWire?', 'answer' => 'Cloud comms platform.'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('search_faqs', ['query' => 'signalwire'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Cloud comms platform', $arr['response']);
    }

    public function testFAQBotSearchNoMatch(): void
    {
        $agent = new FAQBotAgent(
            name: 'faq_bot',
            faqs: [
                ['question' => 'What is SignalWire?', 'answer' => 'Cloud comms platform.'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('search_faqs', ['query' => 'banana'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('No FAQ found', $arr['response']);
    }

    public function testFAQBotSearchKeywordScoring(): void
    {
        $agent = new FAQBotAgent(
            name: 'faq_bot',
            faqs: [
                ['question' => 'What is the pricing model?',   'answer' => 'Pay-as-you-go.'],
                ['question' => 'What is SignalWire pricing?',   'answer' => 'Usage-based pricing.'],
                ['question' => 'How do I sign up?',             'answer' => 'Visit the website.'],
            ],
            suggestRelated: true,
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        // "signalwire pricing" should match the second FAQ best
        $result = $agent->onFunctionCall('search_faqs', ['query' => 'signalwire pricing'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Usage-based pricing', $arr['response']);
    }

    public function testFAQBotSearchRelatedSuggestions(): void
    {
        $agent = new FAQBotAgent(
            name: 'faq_bot',
            faqs: [
                ['question' => 'What is SignalWire?',           'answer' => 'A platform.'],
                ['question' => 'What SignalWire products exist?', 'answer' => 'Many products.'],
                ['question' => 'How much is SignalWire?',       'answer' => 'Affordable.'],
            ],
            suggestRelated: true,
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('search_faqs', ['query' => 'signalwire'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('Related questions:', $arr['response']);
    }

    public function testFAQBotCustomNameAndRoute(): void
    {
        $agent = new FAQBotAgent(
            name: 'my_faq',
            faqs: [
                ['question' => 'Q?', 'answer' => 'A.'],
            ],
            route: '/my_faq',
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertSame('my_faq', $agent->getName());
        $this->assertSame('/my_faq', $agent->getRoute());
    }

    // ==================================================================
    //  ConciergeAgent
    // ==================================================================

    public function testConciergeConstruction(): void
    {
        $agent = new ConciergeAgent(
            name: 'concierge',
            venueInfo: [
                'venue_name'           => 'Grand Hotel',
                'services'             => ['room service', 'spa bookings', 'restaurant reservations'],
                'amenities'            => [
                    'pool' => ['hours' => '7 AM - 10 PM', 'location' => '2nd Floor'],
                    'gym'  => ['hours' => '24 hours',     'location' => '3rd Floor'],
                ],
                'hours_of_operation'   => [
                    'Monday'  => '9 AM - 5 PM',
                    'Tuesday' => '9 AM - 5 PM',
                ],
                'special_instructions' => ['VIP guests get priority'],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $this->assertSame('concierge', $agent->getName());
        $this->assertSame('/concierge', $agent->getRoute());
        $this->assertTrue($agent->promptHasSection('Concierge Role'));
        $this->assertTrue($agent->promptHasSection('Available Services'));
        $this->assertTrue($agent->promptHasSection('Amenities'));
        $this->assertTrue($agent->promptHasSection('Hours of Operation'));
        $this->assertTrue($agent->promptHasSection('Special Instructions'));
    }

    public function testConciergeHasExpectedTools(): void
    {
        $agent = new ConciergeAgent(
            name: 'concierge',
            venueInfo: [
                'venue_name' => 'Test Hotel',
                'services'   => ['room service'],
                'amenities'  => ['pool' => ['hours' => '9-5']],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('check_availability', ['service' => 'pool'], []);
        $this->assertInstanceOf(FunctionResult::class, $result);

        $result2 = $agent->onFunctionCall('get_directions', ['destination' => 'pool'], []);
        $this->assertInstanceOf(FunctionResult::class, $result2);
    }

    public function testConciergeCheckAvailability(): void
    {
        $agent = new ConciergeAgent(
            name: 'concierge',
            venueInfo: [
                'venue_name' => 'Grand Hotel',
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('check_availability', [
            'service' => 'spa',
            'date'    => '2025-06-15',
        ], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('spa', $arr['response']);
        $this->assertStringContainsString('Grand Hotel', $arr['response']);
    }

    public function testConciergeGetDirectionsKnown(): void
    {
        $agent = new ConciergeAgent(
            name: 'concierge',
            venueInfo: [
                'venue_name' => 'Grand Hotel',
                'amenities'  => [
                    'pool' => ['hours' => '9-5', 'location' => '2nd Floor'],
                ],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('get_directions', ['destination' => 'pool'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('2nd Floor', $arr['response']);
    }

    public function testConciergeGetDirectionsUnknown(): void
    {
        $agent = new ConciergeAgent(
            name: 'concierge',
            venueInfo: [
                'venue_name' => 'Grand Hotel',
                'amenities'  => [],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $result = $agent->onFunctionCall('get_directions', ['destination' => 'rooftop'], []);
        $arr = $result->toArray();
        $this->assertStringContainsString('front desk', $arr['response']);
    }

    public function testConciergeSwmlRendering(): void
    {
        $agent = new ConciergeAgent(
            name: 'concierge',
            venueInfo: [
                'venue_name' => 'Test Hotel',
                'services'   => ['room service'],
                'amenities'  => ['pool' => ['hours' => '9-5']],
            ],
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );

        $swml = $agent->renderSwml();
        $this->assertSame('1.0.0', $swml['version']);

        $aiVerbs = array_filter(
            $swml['sections']['main'],
            fn(array $v) => array_key_first($v) === 'ai',
        );
        $this->assertNotEmpty($aiVerbs);

        $aiVerb = array_values($aiVerbs)[0]['ai'];
        $this->assertArrayHasKey('global_data', $aiVerb);
        $this->assertSame('Test Hotel', $aiVerb['global_data']['venue_name']);
    }

    // ==================================================================
    //  Cross-cutting: All prefabs inherit AgentBase
    // ==================================================================

    public function testAllPrefabsInheritAgentBase(): void
    {
        $options = $this->baseOptions();
        $agents = [
            new InfoGathererAgent(
                name: 'info',
                questions: [['key_name' => 'n', 'question_text' => 'Name?']],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new SurveyAgent(
                name: 'survey',
                questions: [['id' => 'q1', 'text' => 'Q?', 'type' => 'rating', 'scale' => 5, 'required' => true]],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new ReceptionistAgent(
                name: 'receptionist',
                departments: [['name' => 'sales', 'description' => 'Sales', 'number' => '+1555']],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new FAQBotAgent(
                name: 'faq',
                faqs: [['question' => 'Q?', 'answer' => 'A.']],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new ConciergeAgent(
                name: 'concierge',
                venueInfo: [
                    'venue_name' => 'Hotel',
                    'services'   => ['room service'],
                    'amenities'  => ['pool' => []],
                ],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),
        ];

        foreach ($agents as $agent) {
            $class = (new \ReflectionClass($agent))->getShortName();
            $this->assertInstanceOf(AgentBase::class, $agent, "{$class} should inherit AgentBase");
        }
    }

    public function testAllPrefabsCanRenderSwml(): void
    {
        $options = $this->baseOptions();
        $agents = [
            new InfoGathererAgent(
                name: 'info',
                questions: [['key_name' => 'n', 'question_text' => 'Name?']],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new SurveyAgent(
                name: 'survey',
                questions: [['id' => 'q1', 'text' => 'Q?', 'type' => 'rating', 'scale' => 5, 'required' => true]],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new ReceptionistAgent(
                name: 'receptionist',
                departments: [['name' => 'sales', 'description' => 'Sales', 'number' => '+1555']],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new FAQBotAgent(
                name: 'faq',
                faqs: [['question' => 'Q?', 'answer' => 'A.']],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),

            new ConciergeAgent(
                name: 'concierge',
                venueInfo: [
                    'venue_name' => 'Hotel',
                    'services'   => ['room service'],
                    'amenities'  => ['pool' => []],
                ],
                basicAuthUser: $options['basicAuthUser'],
                basicAuthPassword: $options['basicAuthPassword']
            ),
        ];

        foreach ($agents as $agent) {
            $class = (new \ReflectionClass($agent))->getShortName();
            $swml = $agent->renderSwml();
            $this->assertSame('1.0.0', $swml['version'], "{$class} renders valid SWML version");
            $this->assertArrayHasKey('sections', $swml, "{$class} renders SWML with sections");
        }
    }

    public function testAllPrefabsHaveHandleRequest(): void
    {
        $options = $this->baseOptions();
        $agent = new InfoGathererAgent(
            name: 'info',
            questions: [['key_name' => 'n', 'question_text' => 'Name?']],
            basicAuthUser: $options['basicAuthUser'],
            basicAuthPassword: $options['basicAuthPassword']
        );

        $this->assertTrue(method_exists($agent, 'handleRequest'));
    }
}
