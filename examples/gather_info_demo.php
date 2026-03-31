<?php
/**
 * Gather Info Mode Demo
 *
 * Demonstrates the contexts system's gather_info mode for structured
 * data collection. Uses the low-level contexts API with setGatherInfo()
 * and addGatherQuestion().
 *
 * Gather info mode presents questions one at a time. Answers are stored
 * in global_data under the configured output key.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:  'Patient Intake Agent',
    route: '/patient-intake',
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->promptAddSection(
    'Role',
    'You are a friendly medical office intake assistant. '
    . 'Collect patient information accurately and professionally.',
);

// Define a context with gather info steps
$ctx = $agent->defineContexts();
$context = $ctx->addContext('default');

// Step 1: Gather patient demographics
$step1 = $context->addStep('demographics');
$step1->setText('Collect the patient\'s basic information.');
$step1->setGatherInfo(
    outputKey: 'patient_demographics',
    prompt:    'Please collect the following patient information.',
);
$step1->addGatherQuestion('full_name', 'What is your full name?', type: 'string');
$step1->addGatherQuestion('date_of_birth', 'What is your date of birth?', type: 'string');
$step1->addGatherQuestion('phone_number', 'What is your phone number?', type: 'string', confirm: true);
$step1->addGatherQuestion('email', 'What is your email address?', type: 'string');
$step1->setValidSteps(['symptoms']);

// Step 2: Gather symptoms
$step2 = $context->addStep('symptoms');
$step2->setText('Ask about the patient\'s current symptoms and reason for visit.');
$step2->setGatherInfo(
    outputKey: 'patient_symptoms',
    prompt:    "Now let's talk about why you're visiting today.",
);
$step2->addGatherQuestion('reason_for_visit', 'What is the main reason for your visit today?', type: 'string');
$step2->addGatherQuestion('symptom_duration', 'How long have you been experiencing these symptoms?', type: 'string');
$step2->addGatherQuestion('pain_level', 'On a scale of 1 to 10, how would you rate your discomfort?', type: 'string');
$step2->setValidSteps(['confirmation']);

// Step 3: Confirmation
$step3 = $context->addStep('confirmation');
$step3->setText(
    'Summarise all the information collected and confirm with the patient '
    . 'that everything is correct. Thank them for their time.',
);
$step3->setStepCriteria('Patient has confirmed all information is correct');

$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting Patient Intake Agent\n";
echo "Available at: http://localhost:3000/patient-intake\n";

$agent->run();
