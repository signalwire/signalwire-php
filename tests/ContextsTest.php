<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Contexts\Context;
use SignalWire\Contexts\ContextBuilder;
use SignalWire\Contexts\GatherInfo;
use SignalWire\Contexts\GatherQuestion;
use SignalWire\Contexts\Step;
use SignalWire\Tests\Support\Shape;

class ContextsTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════════════
    //  GatherQuestion
    // ═══════════════════════════════════════════════════════════════════════

    public function testGatherQuestionConstructionAndToArray(): void
    {
        $q = new GatherQuestion('name', 'What is your name?');

        $this->assertSame('name', $q->getKey());

        $arr = $q->toArray();
        $this->assertSame('name', $arr['key']);
        $this->assertSame('What is your name?', $arr['question']);
        // Default type is 'string', which is omitted from output
        $this->assertArrayNotHasKey('type', $arr);
        $this->assertArrayNotHasKey('confirm', $arr);
        $this->assertArrayNotHasKey('prompt', $arr);
        $this->assertArrayNotHasKey('functions', $arr);
    }

    public function testGatherQuestionWithAllOptions(): void
    {
        $q = new GatherQuestion(
            key: 'age',
            question: 'How old are you?',
            type: 'integer',
            confirm: true,
            prompt: 'Confirm age',
            functions: ['validate_age']
        );

        $arr = $q->toArray();
        $this->assertSame('age', $arr['key']);
        $this->assertSame('How old are you?', $arr['question']);
        $this->assertSame('integer', $arr['type']);
        $this->assertTrue($arr['confirm']);
        $this->assertSame('Confirm age', $arr['prompt']);
        $this->assertSame(['validate_age'], $arr['functions']);
    }

    public function testGatherQuestionDefaultTypeOmitted(): void
    {
        $q = new GatherQuestion('x', 'Q?', 'string');

        $this->assertArrayNotHasKey('type', $q->toArray());
    }

    public function testGatherQuestionNonDefaultTypeIncluded(): void
    {
        $q = new GatherQuestion('x', 'Q?', 'boolean');

        $this->assertSame('boolean', $q->toArray()['type']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GatherInfo
    // ═══════════════════════════════════════════════════════════════════════

    public function testGatherInfoAddQuestionAndToArray(): void
    {
        $gi = new GatherInfo('result_key', 'next_step', 'Please answer');
        $gi->addQuestion('color', 'Favorite color?');
        $gi->addQuestion('food', 'Favorite food?');

        $this->assertCount(2, $gi->getQuestions());
        $this->assertSame('next_step', $gi->getCompletionAction());

        $arr = $gi->toArray();
        $this->assertCount(2, Shape::sub($arr, 'questions'));
        $this->assertSame('color', Shape::at($arr, 'questions', 0, 'key'));
        $this->assertSame('food', Shape::at($arr, 'questions', 1, 'key'));
        $this->assertSame('Please answer', $arr['prompt']);
        $this->assertSame('result_key', $arr['output_key']);
        $this->assertSame('next_step', $arr['completion_action']);
    }

    public function testGatherInfoDefaultsOmitOptionalFields(): void
    {
        $gi = new GatherInfo();
        $gi->addQuestion('k', 'Q?');

        $arr = $gi->toArray();
        $this->assertArrayNotHasKey('prompt', $arr);
        $this->assertArrayNotHasKey('output_key', $arr);
        $this->assertArrayNotHasKey('completion_action', $arr);
    }

    public function testGatherInfoChainsAddQuestion(): void
    {
        $gi = new GatherInfo();
        $result = $gi->addQuestion('a', 'A?');
        $this->assertSame($gi, $result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Step
    // ═══════════════════════════════════════════════════════════════════════

    public function testStepSetTextAndToArray(): void
    {
        $step = new Step('greeting');
        $step->setText('Hello, welcome!');

        $arr = $step->toArray();
        $this->assertSame('greeting', $arr['name']);
        $this->assertSame('Hello, welcome!', $arr['text']);
    }

    public function testStepAddSectionPomRendering(): void
    {
        $step = new Step('intro');
        $step->addSection('Greeting', 'Say hello to the caller.');
        $step->addSection('Instructions', 'Ask for their name.');

        $arr = $step->toArray();
        $text = Shape::at($arr, 'text');
        $this->assertIsString($text);
        $this->assertStringContainsString('## Greeting', $text);
        $this->assertStringContainsString('Say hello to the caller.', $text);
        $this->assertStringContainsString('## Instructions', $text);
        $this->assertStringContainsString('Ask for their name.', $text);
    }

    public function testStepAddBullets(): void
    {
        $step = new Step('rules');
        $step->addBullets('Rules', ['Be polite', 'Be concise']);

        $arr = $step->toArray();
        $text = Shape::at($arr, 'text');
        $this->assertIsString($text);
        $this->assertStringContainsString('## Rules', $text);
        $this->assertStringContainsString('- Be polite', $text);
        $this->assertStringContainsString('- Be concise', $text);
    }

    public function testStepClearSections(): void
    {
        $step = new Step('s');
        $step->setText('initial');
        $step->clearSections();

        // After clearing, addSection should work without conflict
        $step->addSection('Fresh', 'New content');
        $arr = $step->toArray();
        $text = Shape::at($arr, 'text');
        $this->assertIsString($text);
        $this->assertStringContainsString('## Fresh', $text);
    }

    public function testStepClearSectionsRemovesBothTextAndSections(): void
    {
        $step = new Step('s');
        $step->addSection('Title', 'Body');
        $step->clearSections();

        // After clearing, setText should work without conflict
        $step->setText('Plain text');
        $arr = $step->toArray();
        $this->assertSame('Plain text', $arr['text']);
    }

    public function testStepSetTextAfterSectionsThrows(): void
    {
        $step = new Step('s');
        $step->addSection('T', 'B');

        $this->expectException(\LogicException::class);
        $step->setText('conflict');
    }

    public function testStepAddSectionAfterSetTextThrows(): void
    {
        $step = new Step('s');
        $step->setText('plain');

        $this->expectException(\LogicException::class);
        $step->addSection('T', 'B');
    }

    public function testStepAddBulletsAfterSetTextThrows(): void
    {
        $step = new Step('s');
        $step->setText('plain');

        $this->expectException(\LogicException::class);
        $step->addBullets('T', ['a']);
    }

    public function testStepSetStepCriteria(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setStepCriteria('User wants to order');

        $arr = $step->toArray();
        $this->assertSame('User wants to order', $arr['step_criteria']);
    }

    public function testStepSetFunctionsString(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setFunctions('none');

        $arr = $step->toArray();
        $this->assertSame('none', $arr['functions']);
    }

    public function testStepSetFunctionsArray(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setFunctions(['lookup_user', 'get_balance']);

        $arr = $step->toArray();
        $this->assertSame(['lookup_user', 'get_balance'], $arr['functions']);
    }

    public function testStepSetValidSteps(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setValidSteps(['step_a', 'step_b']);

        $arr = $step->toArray();
        $this->assertSame(['step_a', 'step_b'], $arr['valid_steps']);
        $this->assertSame(['step_a', 'step_b'], $step->getValidSteps());
    }

    public function testStepSetValidContexts(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setValidContexts(['billing', 'support']);

        $arr = $step->toArray();
        $this->assertSame(['billing', 'support'], $arr['valid_contexts']);
        $this->assertSame(['billing', 'support'], $step->getValidContexts());
    }

    public function testStepSetEnd(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setEnd(true);

        $arr = $step->toArray();
        $this->assertTrue($arr['end']);
    }

    public function testStepSetEndFalseOmitted(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setEnd(false);

        $arr = $step->toArray();
        $this->assertArrayNotHasKey('end', $arr);
    }

    public function testStepSetSkipUserTurn(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setSkipUserTurn(true);

        $arr = $step->toArray();
        $this->assertTrue($arr['skip_user_turn']);
    }

    public function testStepSetSkipUserTurnFalseOmitted(): void
    {
        $step = new Step('s');
        $step->setText('text');

        $this->assertArrayNotHasKey('skip_user_turn', $step->toArray());
    }

    public function testStepSetSkipToNextStep(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setSkipToNextStep(true);

        $arr = $step->toArray();
        $this->assertTrue($arr['skip_to_next_step']);
    }

    public function testStepSetSkipToNextStepFalseOmitted(): void
    {
        $step = new Step('s');
        $step->setText('text');

        $this->assertArrayNotHasKey('skip_to_next_step', $step->toArray());
    }

    public function testStepSetGatherInfo(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setGatherInfo(
            output_key: 'info',
            completion_action: 'next_step',
            prompt: 'Answer these questions'
        );
        $step->addGatherQuestion('name', 'Your name?');

        $arr = $step->toArray();
        $this->assertArrayHasKey('gather_info', $arr);
        $this->assertSame('info', Shape::at($arr, 'gather_info', 'output_key'));
        $this->assertSame('next_step', Shape::at($arr, 'gather_info', 'completion_action'));
        $this->assertSame('Answer these questions', Shape::at($arr, 'gather_info', 'prompt'));
        $this->assertCount(1, Shape::sub($arr, 'gather_info', 'questions'));
    }

    public function testStepAddGatherQuestionWithoutSetGatherInfoThrows(): void
    {
        $step = new Step('s');
        $step->setText('text');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Must call setGatherInfo()');
        $step->addGatherQuestion('email', 'Your email?');
    }

    public function testStepToArraySerialization(): void
    {
        $step = new Step('full_step');
        $step->setText('Do the thing');
        $step->setStepCriteria('When user asks');
        $step->setFunctions(['fn_a']);
        $step->setValidSteps(['next']);
        $step->setValidContexts(['other']);
        $step->setEnd(true);
        $step->setSkipUserTurn(true);
        $step->setSkipToNextStep(true);

        $arr = $step->toArray();
        $this->assertSame('full_step', $arr['name']);
        $this->assertSame('Do the thing', $arr['text']);
        $this->assertSame('When user asks', $arr['step_criteria']);
        $this->assertSame(['fn_a'], $arr['functions']);
        $this->assertSame(['next'], $arr['valid_steps']);
        $this->assertSame(['other'], $arr['valid_contexts']);
        $this->assertTrue($arr['end']);
        $this->assertTrue($arr['skip_user_turn']);
        $this->assertTrue($arr['skip_to_next_step']);
    }

    public function testStepMinimalToArrayOmitsOptionalKeys(): void
    {
        $step = new Step('minimal');
        $step->setText('Just text');

        $arr = $step->toArray();
        $this->assertSame(['name', 'text'], array_keys($arr));
    }

    public function testStepResetFields(): void
    {
        $step = new Step('s');
        $step->setText('text');
        $step->setResetSystemPrompt('New system prompt');
        $step->setResetUserPrompt('New user prompt');
        $step->setResetConsolidate(true);
        $step->setResetFullReset(true);

        $arr = $step->toArray();
        $this->assertArrayHasKey('reset', $arr);
        $this->assertSame('New system prompt', Shape::at($arr, 'reset', 'system_prompt'));
        $this->assertSame('New user prompt', Shape::at($arr, 'reset', 'user_prompt'));
        $this->assertTrue(Shape::at($arr, 'reset', 'consolidate'));
        $this->assertTrue(Shape::at($arr, 'reset', 'full_reset'));
    }

    public function testStepNoResetWhenNotSet(): void
    {
        $step = new Step('s');
        $step->setText('text');

        $this->assertArrayNotHasKey('reset', $step->toArray());
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Context
    // ═══════════════════════════════════════════════════════════════════════

    public function testContextAddStepAndGetStep(): void
    {
        $ctx = new Context('default');
        $step = $ctx->addStep('greeting')->setText('Hello');

        $this->assertSame($step, $ctx->getStep('greeting'));
        $this->assertNull($ctx->getStep('nonexistent'));
    }

    public function testContextAddStepWithShorthandKwargs(): void
    {
        $ctx = new Context('default');
        // Mirror Python's Context.add_step(name, *, task, bullets, criteria,
        // functions, valid_steps): named-arg shorthand fills the step's
        // POM "Task"/"Process" sections, criteria, functions, and valid_steps.
        $step = $ctx->addStep(
            's1',
            task: 'Greet the caller',
            bullets: ['Be polite', 'Ask their name'],
            criteria: 'always',
            functions: ['fn1'],
            valid_steps: ['s2']
        );

        $arr = $step->toArray();
        $text = Shape::at($arr, 'text');
        $this->assertIsString($text);
        $this->assertStringContainsString('## Task', $text);
        $this->assertStringContainsString('Greet the caller', $text);
        $this->assertStringContainsString('## Process', $text);
        $this->assertStringContainsString('- Be polite', $text);
        $this->assertSame('always', $arr['step_criteria']);
        $this->assertSame(['fn1'], $arr['functions']);
        $this->assertSame(['s2'], $arr['valid_steps']);
    }

    public function testContextDuplicateStepThrows(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('greeting')->setText('Hi');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Step 'greeting' already exists");
        $ctx->addStep('greeting')->setText('Hi again');
    }

    public function testContextRemoveStep(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('a')->setText('A');
        $ctx->addStep('b')->setText('B');
        $ctx->removeStep('a');

        $this->assertNull($ctx->getStep('a'));
        $this->assertNotNull($ctx->getStep('b'));
        $this->assertSame(['b'], $ctx->getStepOrder());
    }

    public function testContextRemoveNonexistentStepIsNoop(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('a')->setText('A');
        $ctx->removeStep('nonexistent');

        $this->assertCount(1, $ctx->getSteps());
    }

    public function testContextMoveStep(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('a')->setText('A');
        $ctx->addStep('b')->setText('B');
        $ctx->addStep('c')->setText('C');

        $ctx->moveStep('c', 0);
        $this->assertSame(['c', 'a', 'b'], $ctx->getStepOrder());
    }

    public function testContextMoveStepToMiddle(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('a')->setText('A');
        $ctx->addStep('b')->setText('B');
        $ctx->addStep('c')->setText('C');

        $ctx->moveStep('a', 1);
        $this->assertSame(['b', 'a', 'c'], $ctx->getStepOrder());
    }

    public function testContextMoveNonexistentStepThrows(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('a')->setText('A');

        $this->expectException(\LogicException::class);
        $ctx->moveStep('z', 0);
    }

    // ── Context prompt modes ─────────────────────────────────────────────

    public function testContextPromptTextMode(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step text');
        $ctx->setPrompt('You are a helpful assistant.');

        $arr = $ctx->toArray();
        $this->assertSame('You are a helpful assistant.', $arr['prompt']);
    }

    public function testContextPromptPomMode(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step text');
        $ctx->addSection('Role', 'You are a concierge.');
        $ctx->addBullets('Rules', ['Be kind', 'Be brief']);

        $arr = $ctx->toArray();
        $prompt = Shape::at($arr, 'prompt');
        $this->assertIsString($prompt);
        $this->assertStringContainsString('## Role', $prompt);
        $this->assertStringContainsString('You are a concierge.', $prompt);
        $this->assertStringContainsString('- Be kind', $prompt);
        $this->assertStringContainsString('- Be brief', $prompt);
    }

    public function testContextSetPromptAfterSectionsThrows(): void
    {
        $ctx = new Context('default');
        $ctx->addSection('T', 'B');

        $this->expectException(\LogicException::class);
        $ctx->setPrompt('conflict');
    }

    public function testContextAddSectionAfterSetPromptThrows(): void
    {
        $ctx = new Context('default');
        $ctx->setPrompt('text');

        $this->expectException(\LogicException::class);
        $ctx->addSection('T', 'B');
    }

    public function testContextAddBulletsAfterSetPromptThrows(): void
    {
        $ctx = new Context('default');
        $ctx->setPrompt('text');

        $this->expectException(\LogicException::class);
        $ctx->addBullets('T', ['a']);
    }

    // ── Context system prompt ────────────────────────────────────────────

    public function testContextSystemPromptText(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->setSystemPrompt('System instructions here.');

        $arr = $ctx->toArray();
        $this->assertSame('System instructions here.', $arr['system_prompt']);
    }

    public function testContextSystemPromptPom(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->addSystemSection('Behavior', 'Be professional.');
        $ctx->addSystemBullets('Constraints', ['No profanity']);

        $arr = $ctx->toArray();
        $systemPrompt = Shape::at($arr, 'system_prompt');
        $this->assertIsString($systemPrompt);
        $this->assertStringContainsString('## Behavior', $systemPrompt);
        $this->assertStringContainsString('Be professional.', $systemPrompt);
        $this->assertStringContainsString('- No profanity', $systemPrompt);
    }

    public function testContextSystemPromptConflicts(): void
    {
        $ctx = new Context('default');
        $ctx->setSystemPrompt('plain');

        $this->expectException(\LogicException::class);
        $ctx->addSystemSection('T', 'B');
    }

    public function testContextSystemBulletsConflict(): void
    {
        $ctx = new Context('default');
        $ctx->setSystemPrompt('plain');

        $this->expectException(\LogicException::class);
        $ctx->addSystemBullets('T', ['a']);
    }

    public function testContextAddSystemSectionAfterSectionsConflict(): void
    {
        $ctx = new Context('default');
        $ctx->addSystemSection('A', 'B');

        $this->expectException(\LogicException::class);
        $ctx->setSystemPrompt('plain');
    }

    // ── Context fillers ──────────────────────────────────────────────────

    public function testContextEnterFillers(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->setEnterFillers(['en' => ['Please wait', 'One moment']]);

        $arr = $ctx->toArray();
        $this->assertSame(['en' => ['Please wait', 'One moment']], $arr['enter_fillers']);
    }

    public function testContextExitFillers(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->setExitFillers(['en' => ['Goodbye']]);

        $arr = $ctx->toArray();
        $this->assertSame(['en' => ['Goodbye']], $arr['exit_fillers']);
    }

    public function testContextAddEnterFiller(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->addEnterFiller('en', ['Hold on', 'Just a sec']);
        $ctx->addEnterFiller('es', ['Un momento']);

        $arr = $ctx->toArray();
        $this->assertSame(['Hold on', 'Just a sec'], Shape::at($arr, 'enter_fillers', 'en'));
        $this->assertSame(['Un momento'], Shape::at($arr, 'enter_fillers', 'es'));
    }

    public function testContextAddExitFiller(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->addExitFiller('en', ['Bye', 'See you']);

        $arr = $ctx->toArray();
        $this->assertSame(['Bye', 'See you'], Shape::at($arr, 'exit_fillers', 'en'));
    }

    public function testContextFillersOmittedWhenNotSet(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');

        $arr = $ctx->toArray();
        $this->assertArrayNotHasKey('enter_fillers', $arr);
        $this->assertArrayNotHasKey('exit_fillers', $arr);
    }

    // ── Context toArray ──────────────────────────────────────────────────

    public function testContextToArrayStepOrdering(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('first')->setText('First');
        $ctx->addStep('second')->setText('Second');
        $ctx->addStep('third')->setText('Third');

        $arr = $ctx->toArray();
        $this->assertSame('first', Shape::at($arr, 'steps', 0, 'name'));
        $this->assertSame('second', Shape::at($arr, 'steps', 1, 'name'));
        $this->assertSame('third', Shape::at($arr, 'steps', 2, 'name'));
    }

    public function testContextToArrayFullSerialization(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step text');
        $ctx->setPrompt('Context prompt');
        $ctx->setSystemPrompt('System prompt');
        $ctx->setPostPrompt('Post prompt');
        $ctx->setUserPrompt('User prompt');
        $ctx->setConsolidate(true);
        $ctx->setFullReset(true);
        $ctx->setIsolated(true);
        $ctx->setValidContexts(['billing']);
        $ctx->setValidSteps(['step1']);
        $ctx->setEnterFillers(['en' => ['Wait']]);
        $ctx->setExitFillers(['en' => ['Bye']]);

        $arr = $ctx->toArray();
        $this->assertCount(1, Shape::sub($arr, 'steps'));
        $this->assertSame('Context prompt', $arr['prompt']);
        $this->assertSame('System prompt', $arr['system_prompt']);
        $this->assertSame('Post prompt', $arr['post_prompt']);
        $this->assertSame('User prompt', $arr['user_prompt']);
        $this->assertTrue($arr['consolidate']);
        $this->assertTrue($arr['full_reset']);
        $this->assertTrue($arr['isolated']);
        $this->assertSame(['billing'], $arr['valid_contexts']);
        $this->assertSame(['step1'], $arr['valid_steps']);
        $this->assertSame(['en' => ['Wait']], $arr['enter_fillers']);
        $this->assertSame(['en' => ['Bye']], $arr['exit_fillers']);
    }

    public function testContextToArrayOmitsUnsetOptionalFields(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Hi');

        $arr = $ctx->toArray();
        $this->assertArrayHasKey('steps', $arr);
        $this->assertArrayNotHasKey('prompt', $arr);
        $this->assertArrayNotHasKey('system_prompt', $arr);
        $this->assertArrayNotHasKey('post_prompt', $arr);
        $this->assertArrayNotHasKey('user_prompt', $arr);
        $this->assertArrayNotHasKey('consolidate', $arr);
        $this->assertArrayNotHasKey('full_reset', $arr);
        $this->assertArrayNotHasKey('isolated', $arr);
        $this->assertArrayNotHasKey('valid_contexts', $arr);
        $this->assertArrayNotHasKey('valid_steps', $arr);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ContextBuilder
    // ═══════════════════════════════════════════════════════════════════════

    public function testContextBuilderAddContextAndGetContext(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');

        $this->assertSame($ctx, $builder->getContext('default'));
        $this->assertNull($builder->getContext('nonexistent'));
    }

    public function testContextBuilderHasContexts(): void
    {
        $builder = new ContextBuilder();
        $this->assertFalse($builder->hasContexts());

        $builder->addContext('default');
        $this->assertTrue($builder->hasContexts());
    }

    public function testContextBuilderDuplicateContextThrows(): void
    {
        $builder = new ContextBuilder();
        $builder->addContext('default');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Context 'default' already exists");
        $builder->addContext('default');
    }

    // ── ContextBuilder validate ──────────────────────────────────────────

    public function testValidateSingleContextMustBeDefault(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('custom');
        $ctx->addStep('s')->setText('Hello');

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("must be named 'default'", $errors[0]);
    }

    public function testValidateSingleContextNamedDefaultPasses(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $ctx->addStep('s')->setText('Hello');

        $errors = $builder->validate();
        $this->assertEmpty($errors);
    }

    public function testValidateEmptyContextRejected(): void
    {
        $builder = new ContextBuilder();
        $builder->addContext('default');
        // No steps added

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must have at least one step', $errors[0]);
    }

    public function testValidateNoContextsRejectsEmpty(): void
    {
        $builder = new ContextBuilder();
        $errors = $builder->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('At least one context', $errors[0]);
    }

    public function testValidateMultipleContextsNonDefaultAllowed(): void
    {
        $builder = new ContextBuilder();
        $builder->addContext('billing')->addStep('s')->setText('Billing');
        $builder->addContext('support')->addStep('s')->setText('Support');

        $errors = $builder->validate();
        $this->assertEmpty($errors);
    }

    public function testValidateInvalidStepReference(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $ctx->addStep('s1')->setText('Step 1')->setValidSteps(['nonexistent']);

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("unknown step 'nonexistent'", $errors[0]);
    }

    public function testValidateNextStepReferenceIsAllowed(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $ctx->addStep('s1')->setText('Step 1')->setValidSteps(['next']);

        $errors = $builder->validate();
        $this->assertEmpty($errors);
    }

    public function testValidateInvalidContextReferenceAtContextLevel(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->setValidContexts(['ghost']);

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("unknown context 'ghost'", $errors[0]);
    }

    public function testValidateInvalidContextReferenceAtStepLevel(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $ctx->addStep('s')->setText('Step')->setValidContexts(['ghost']);

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("unknown context 'ghost'", $errors[0]);
    }

    public function testValidateGatherInfoNoQuestions(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $step = $ctx->addStep('s')->setText('Step');
        $step->setGatherInfo(output_key: 'info');

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('no questions', $errors[0]);
    }

    public function testValidateGatherInfoDuplicateKeys(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $step = $ctx->addStep('s')->setText('Step');
        $step->setGatherInfo();
        $step->addGatherQuestion('name', 'Name?');
        $step->addGatherQuestion('name', 'Name again?');

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("duplicate gather_info question key 'name'", $errors[0]);
    }

    public function testValidateGatherInfoCompletionActionNextStepLastStep(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $step = $ctx->addStep('last')->setText('Step');
        $step->setGatherInfo(completion_action: 'next_step');
        $step->addGatherQuestion('q', 'Q?');

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('next_step', $errors[0]);
        $this->assertStringContainsString('last step', $errors[0]);
    }

    public function testValidateGatherInfoCompletionActionReferencesUnknownStep(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $step = $ctx->addStep('s')->setText('Step');
        $step->setGatherInfo(completion_action: 'unknown_step');
        $step->addGatherQuestion('q', 'Q?');

        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unknown_step', $errors[0]);
        $this->assertStringContainsString('is not a step', $errors[0]);
    }

    // ── ContextBuilder toArray ───────────────────────────────────────────

    public function testContextBuilderToArray(): void
    {
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $ctx->addStep('greeting')->setText('Hello!');
        $ctx->setPrompt('Be helpful');

        $arr = $builder->toArray();
        $this->assertArrayHasKey('default', $arr);
        $this->assertCount(1, Shape::sub($arr, 'default', 'steps'));
        $this->assertSame('greeting', Shape::at($arr, 'default', 'steps', 0, 'name'));
        $this->assertSame('Be helpful', Shape::at($arr, 'default', 'prompt'));
    }

    public function testContextBuilderToArrayPreservesOrder(): void
    {
        $builder = new ContextBuilder();
        $builder->addContext('billing')->addStep('s')->setText('Bill');
        $builder->addContext('support')->addStep('s')->setText('Sup');
        $builder->addContext('default')->addStep('s')->setText('Main');

        $arr = $builder->toArray();
        $keys = array_keys($arr);
        $this->assertSame(['billing', 'support', 'default'], $keys);
    }

    public function testContextBuilderToArrayThrowsOnValidationFailure(): void
    {
        $builder = new ContextBuilder();
        $builder->addContext('default');
        // Empty context, no steps

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Validation failed');
        $builder->toArray();
    }

    // ── createSimpleContext ──────────────────────────────────────────────

    public function testCreateSimpleContext(): void
    {
        $builder = ContextBuilder::createSimpleContext('default');

        $this->assertTrue($builder->hasContexts());
        $this->assertNotNull($builder->getContext('default'));
    }

    public function testCreateSimpleContextCanBeUsedEndToEnd(): void
    {
        $builder = ContextBuilder::createSimpleContext('default');
        $defaultCtx = $builder->getContext('default');
        $this->assertNotNull($defaultCtx);
        $defaultCtx->addStep('greet')->setText('Hi there');

        $arr = $builder->toArray();
        $this->assertArrayHasKey('default', $arr);
        $this->assertSame('greet', Shape::at($arr, 'default', 'steps', 0, 'name'));
    }

    // ── Method chaining on Context ───────────────────────────────────────

    public function testContextMethodChaining(): void
    {
        $ctx = new Context('default');

        $this->assertSame($ctx, $ctx->setPrompt('text'));
        $ctx2 = new Context('c2');
        $this->assertSame($ctx2, $ctx2->setSystemPrompt('sys'));
        $this->assertSame($ctx2, $ctx2->setPostPrompt('post'));
        $this->assertSame($ctx2, $ctx2->setConsolidate(true));
        $this->assertSame($ctx2, $ctx2->setFullReset(true));
        $this->assertSame($ctx2, $ctx2->setUserPrompt('u'));
        $this->assertSame($ctx2, $ctx2->setIsolated(true));
        $this->assertSame($ctx2, $ctx2->setValidContexts([]));
        $this->assertSame($ctx2, $ctx2->setValidSteps([]));
        $this->assertSame($ctx2, $ctx2->setEnterFillers([]));
        $this->assertSame($ctx2, $ctx2->setExitFillers([]));
        $this->assertSame($ctx2, $ctx2->removeStep('nope'));
    }

    public function testStepMethodChaining(): void
    {
        $step = new Step('s');

        $this->assertSame($step, $step->setText('t'));
        $step->clearSections();
        $this->assertSame($step, $step->addSection('T', 'B'));
        $step->clearSections();
        $this->assertSame($step, $step->addBullets('T', ['a']));
        $step->clearSections();
        $step->setText('t');
        $this->assertSame($step, $step->setStepCriteria('c'));
        $this->assertSame($step, $step->setFunctions('none'));
        $this->assertSame($step, $step->setValidSteps([]));
        $this->assertSame($step, $step->setValidContexts([]));
        $this->assertSame($step, $step->setEnd(true));
        $this->assertSame($step, $step->setSkipUserTurn(true));
        $this->assertSame($step, $step->setSkipToNextStep(true));
        $this->assertSame($step, $step->setGatherInfo());
        $this->assertSame($step, $step->addGatherQuestion('k', 'Q?'));
        $this->assertSame($step, $step->clearSections());
        $this->assertSame($step, $step->setResetSystemPrompt('sp'));
        $this->assertSame($step, $step->setResetUserPrompt('up'));
        $this->assertSame($step, $step->setResetConsolidate(true));
        $this->assertSame($step, $step->setResetFullReset(true));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Python parity — newly-closed signature gaps
    // ═══════════════════════════════════════════════════════════════════════

    public function testContextBuilderConstructorAcceptsAgent(): void
    {
        // Mirror Python's ContextBuilder(agent) — pass an agent reference.
        $agent = new class () {
            /** @var array<string,bool> */
            public array $tools = [];

            /** @return list<string> */
            public function getRegisteredToolNames(): array
            {
                return array_keys($this->tools);
            }
        };
        $agent->tools = ['lookup_account' => true];

        $builder = new ContextBuilder($agent);

        // The agent supplies registered tool names — collision validation
        // should pull them in automatically.
        $ctx = $builder->addContext('default');
        $ctx->addStep('s')->setText('hi');
        $errors = $builder->validate();
        $this->assertSame([], $errors, 'no collision so validate is clean');

        // Now add a colliding tool name (one of the reserved names).
        $agent->tools['next_step'] = true;
        $errors = $builder->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('next_step', $errors[0]);
        $this->assertStringContainsString('reserved', $errors[0]);
    }

    public function testContextBuilderConstructorIsOptional(): void
    {
        // Default no-arg shape still works (matches Python's optional param).
        $builder = new ContextBuilder();
        $ctx = $builder->addContext('default');
        $ctx->addStep('s')->setText('hi');
        $this->assertSame([], $builder->validate());
    }

    public function testCreateSimpleContextReturnsContext(): void
    {
        // Python's signalwire.core.contexts.create_simple_context(name) is a
        // module-level helper returning a Context. PHP (PSR-4, no module-level
        // free functions) hosts it as the static Context::createSimpleContext,
        // projected to the module name via FREE_FUNCTION_PROJECTIONS.
        $ctx = Context::createSimpleContext('greeting');
        $this->assertSame('greeting', $ctx->getName());
    }

    public function testCreateSimpleContextDefaultName(): void
    {
        // Default name is "default" (matches Python).
        $ctx = Context::createSimpleContext();
        $this->assertSame('default', $ctx->getName());
    }

    public function testGatherInfoAddQuestionPositional(): void
    {
        // Mirror Python's GatherInfo.add_question(key, question, **kwargs).
        $gi = new GatherInfo();
        $gi->addQuestion('food', 'What food?', [
            'type' => 'string',
            'confirm' => true,
            'prompt' => 'Confirm food',
            'functions' => ['validate_food'],
        ]);

        $arr = $gi->toArray();
        $this->assertSame('food', Shape::at($arr, 'questions', 0, 'key'));
        $this->assertSame('What food?', Shape::at($arr, 'questions', 0, 'question'));
        $this->assertTrue(Shape::at($arr, 'questions', 0, 'confirm'));
        $this->assertSame('Confirm food', Shape::at($arr, 'questions', 0, 'prompt'));
        $this->assertSame(['validate_food'], Shape::at($arr, 'questions', 0, 'functions'));
    }

    public function testStepSetGatherInfoNamedArgs(): void
    {
        // Mirror Python's Step.set_gather_info(*, output_key, completion_action, prompt).
        $step = new Step('s');
        $step->setText('Body');
        $step->setGatherInfo(
            output_key: 'collected',
            completion_action: 'next_step',
            prompt: 'Be friendly'
        );
        $step->addGatherQuestion('color', 'Color?', confirm: true);

        $arr = $step->toArray();
        $this->assertSame('collected', Shape::at($arr, 'gather_info', 'output_key'));
        $this->assertSame('next_step', Shape::at($arr, 'gather_info', 'completion_action'));
        $this->assertSame('Be friendly', Shape::at($arr, 'gather_info', 'prompt'));
        $this->assertTrue(Shape::at($arr, 'gather_info', 'questions', 0, 'confirm'));
    }

    public function testStepAddGatherQuestionAllNamedArgs(): void
    {
        // Mirror Python's Step.add_gather_question(key, question, type,
        // confirm, prompt, functions). Verify all six survive the round-trip.
        $step = new Step('s');
        $step->setText('Body');
        $step->setGatherInfo();
        $step->addGatherQuestion(
            key: 'age',
            question: 'How old?',
            type: 'integer',
            confirm: true,
            prompt: 'Be precise',
            functions: ['validate_age']
        );

        $gatherInfo = $step->getGatherInfo();
        $this->assertNotNull($gatherInfo);
        $q = Shape::sub($gatherInfo->toArray(), 'questions', 0);
        $this->assertSame('age', $q['key']);
        $this->assertSame('How old?', $q['question']);
        $this->assertSame('integer', $q['type']);
        $this->assertTrue($q['confirm']);
        $this->assertSame('Be precise', $q['prompt']);
        $this->assertSame(['validate_age'], $q['functions']);
    }

    public function testContextAddEnterFillerAcceptsList(): void
    {
        // Mirror Python's Context.add_enter_filler(language_code, fillers:
        // List[str]). Multiple phrases per language survive.
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->addEnterFiller('en-US', ['Welcome', 'Hi', 'Hello']);
        $ctx->addEnterFiller('es', ['Hola', 'Bienvenido']);

        $arr = $ctx->toArray();
        $this->assertSame(['Welcome', 'Hi', 'Hello'], Shape::at($arr, 'enter_fillers', 'en-US'));
        $this->assertSame(['Hola', 'Bienvenido'], Shape::at($arr, 'enter_fillers', 'es'));
    }

    public function testContextSetEnterFillersAcceptsNestedMap(): void
    {
        // Mirror Python's Context.set_enter_fillers(enter_fillers: Dict[str,
        // List[str]]) — full map replacement.
        $ctx = new Context('default');
        $ctx->addStep('s')->setText('Step');
        $ctx->setEnterFillers([
            'en-US' => ['Welcome', 'Hi'],
            'default' => ['Entering...'],
        ]);

        $arr = $ctx->toArray();
        $this->assertSame(['Welcome', 'Hi'], Shape::at($arr, 'enter_fillers', 'en-US'));
        $this->assertSame(['Entering...'], Shape::at($arr, 'enter_fillers', 'default'));
    }

    public function testContextAddStepAllNamedKwargs(): void
    {
        // Mirror Python's Context.add_step(name, *, task, bullets, criteria,
        // functions, valid_steps).
        $ctx = new Context('default');
        $step = $ctx->addStep(
            'survey',
            task: 'Survey the customer',
            bullets: ['Confirm name', 'Ask satisfaction', 'Collect feedback'],
            criteria: 'All three answers gathered',
            functions: ['log_response'],
            valid_steps: ['next']
        );

        $this->assertSame('survey', $step->getName());
        $arr = $step->toArray();
        $text = Shape::at($arr, 'text');
        $this->assertIsString($text);
        $this->assertStringContainsString('## Task', $text);
        $this->assertStringContainsString('Survey the customer', $text);
        $this->assertStringContainsString('## Process', $text);
        $this->assertStringContainsString('- Confirm name', $text);
        $this->assertStringContainsString('- Ask satisfaction', $text);
        $this->assertStringContainsString('- Collect feedback', $text);
        $this->assertSame('All three answers gathered', $arr['step_criteria']);
        $this->assertSame(['log_response'], $arr['functions']);
        $this->assertSame(['next'], $arr['valid_steps']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  set_history (parity with Python Step/Context.set_history)
    // ═══════════════════════════════════════════════════════════════════════

    public function testStepSetHistoryEmitsEachMode(): void
    {
        foreach (['keep', 'default', 'hide'] as $mode) {
            $step = (new Step('s'))->setText('do it')->setHistory($mode);
            $arr = $step->toArray();
            $this->assertSame($mode, $arr['history']);
        }
    }

    public function testStepSetHistoryIsFluent(): void
    {
        $step = new Step('s');
        $this->assertSame($step, $step->setHistory('keep'));
    }

    public function testStepOmitsHistoryWhenUnset(): void
    {
        $arr = (new Step('s'))->setText('do it')->toArray();
        $this->assertArrayNotHasKey('history', $arr);
    }

    public function testStepSetHistoryRejectsInvalidMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Step('s'))->setHistory('nonsense');
    }

    public function testContextSetHistoryEmitsEachMode(): void
    {
        foreach (['keep', 'default', 'hide'] as $mode) {
            $ctx = new Context('default');
            $ctx->addStep('s', task: 'do it');
            $ctx->setHistory($mode);
            $arr = $ctx->toArray();
            $this->assertSame($mode, $arr['history']);
        }
    }

    public function testContextSetHistoryIsFluent(): void
    {
        $ctx = new Context('default');
        $this->assertSame($ctx, $ctx->setHistory('hide'));
    }

    public function testContextOmitsHistoryWhenUnset(): void
    {
        $ctx = new Context('default');
        $ctx->addStep('s', task: 'do it');
        $arr = $ctx->toArray();
        $this->assertArrayNotHasKey('history', $arr);
    }

    public function testContextSetHistoryRejectsInvalidMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Context('default'))->setHistory('nonsense');
    }
}
