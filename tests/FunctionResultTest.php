<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWAIG\RecordDirection;
use SignalWire\SWAIG\TapDirection;

class FunctionResultTest extends TestCase
{
    // ── Construction ──────────────────────────────────────────────────────

    public function testDefaultConstructionHasEmptyResponseAndNoPostProcess(): void
    {
        // An otherwise-empty result defaults to "Action completed." (Python parity).
        $fr = new FunctionResult();
        $arr = $fr->toArray();

        $this->assertSame('Action completed.', $arr['response']);
        $this->assertArrayNotHasKey('action', $arr);
        $this->assertArrayNotHasKey('post_process', $arr);
    }

    public function testConstructionWithResponseAndPostProcess(): void
    {
        // post_process only appears alongside an action (Python parity).
        $fr = new FunctionResult('hello', true);
        $fr->addAction(['stop' => true]);
        $arr = $fr->toArray();

        $this->assertSame('hello', $arr['response']);
        $this->assertTrue($arr['post_process']);
    }

    public function testConstructionWithNullResponseDefaultsToEmptyString(): void
    {
        // null defaults to '' internally; with an action present, the empty
        // response is omitted (not emitted as null).
        $fr = new FunctionResult(null);
        $fr->addAction(['stop' => true]);
        $this->assertArrayNotHasKey('response', $fr->toArray());
    }

    // ── Core ──────────────────────────────────────────────────────────────

    public function testSetResponse(): void
    {
        $fr = new FunctionResult();
        $fr->setResponse('updated');
        $this->assertSame('updated', $fr->toArray()['response']);
    }

    public function testSetPostProcessTrue(): void
    {
        $fr = new FunctionResult();
        $fr->setPostProcess(true);
        $fr->addAction(['stop' => true]);
        $this->assertTrue($fr->toArray()['post_process']);
    }

    public function testSetPostProcessFalseExcludesKey(): void
    {
        $fr = new FunctionResult('', true);
        $fr->setPostProcess(false);
        $this->assertArrayNotHasKey('post_process', $fr->toArray());
    }

    public function testAddAction(): void
    {
        $fr = new FunctionResult();
        $fr->addAction(['say' => 'hi']);
        $arr = $fr->toArray();

        $this->assertCount(1, $arr['action']);
        $this->assertSame(['say' => 'hi'], $arr['action'][0]);
    }

    public function testAddActions(): void
    {
        $fr = new FunctionResult();
        $fr->addActions([['say' => 'a'], ['say' => 'b']]);
        $arr = $fr->toArray();

        $this->assertCount(2, $arr['action']);
        $this->assertSame('a', $arr['action'][0]['say']);
        $this->assertSame('b', $arr['action'][1]['say']);
    }

    // ── Serialization ─────────────────────────────────────────────────────

    public function testToArrayOmitsEmptyResponseWhenActionPresent(): void
    {
        // Empty response is omitted when an action is present (Python parity).
        $fr = new FunctionResult();
        $fr->addAction(['stop' => true]);
        $this->assertArrayNotHasKey('response', $fr->toArray());
    }

    public function testToArrayOmitsActionWhenEmpty(): void
    {
        $fr = new FunctionResult();
        $this->assertArrayNotHasKey('action', $fr->toArray());
    }

    public function testToArrayIncludesActionWhenNonEmpty(): void
    {
        $fr = new FunctionResult();
        $fr->addAction(['stop' => true]);
        $this->assertArrayHasKey('action', $fr->toArray());
    }

    public function testToArrayOmitsPostProcessWhenFalse(): void
    {
        $fr = new FunctionResult();
        $this->assertArrayNotHasKey('post_process', $fr->toArray());
    }

    public function testToArrayIncludesPostProcessWhenTrue(): void
    {
        $fr = new FunctionResult('resp', true);
        $fr->addAction(['stop' => true]);
        $this->assertArrayHasKey('post_process', $fr->toArray());
        $this->assertTrue($fr->toArray()['post_process']);
    }

    public function testToArrayOmitsPostProcessWithoutAction(): void
    {
        // post_process is dropped when there are no actions, even if set true
        // (Python to_dict: `if self.post_process and self.action`).
        $fr = new FunctionResult('resp', true);
        $this->assertArrayNotHasKey('post_process', $fr->toArray());
    }

    public function testToJsonProducesValidJson(): void
    {
        $fr = new FunctionResult('test');
        $fr->addAction(['say' => 'hello']);
        $json = $fr->toJson();

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertSame('test', $decoded['response']);
        $this->assertSame('hello', $decoded['action'][0]['say']);
    }

    // ── Call Control ──────────────────────────────────────────────────────

    public function testConnectBasic(): void
    {
        $fr = new FunctionResult();
        $fr->connect('+15551234567');
        $action = $fr->toArray()['action'][0];

        $this->assertArrayHasKey('SWML', $action);
        $connect = $action['SWML']['sections']['main'][0]['connect'];
        $this->assertSame('+15551234567', $connect['to']);
        $this->assertArrayNotHasKey('from', $connect);
        // final defaults true -> top-level "transfer" + SWML "version" (parity with Python connect)
        $this->assertSame('true', $action['transfer']);
        $this->assertSame('1.0.0', $action['SWML']['version']);
    }

    public function testConnectWithFrom(): void
    {
        $fr = new FunctionResult();
        $fr->connect('+15551234567', false, '+15559876543');
        $connect = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['connect'];

        $this->assertSame('+15559876543', $connect['from']);
    }

    public function testConnectWithFinalParam(): void
    {
        $fr = new FunctionResult();
        $fr->connect('+15551234567', true);
        $action = $fr->toArray()['action'][0];

        $this->assertArrayHasKey('SWML', $action);
        $this->assertSame('+15551234567', $action['SWML']['sections']['main'][0]['connect']['to']);
        $this->assertSame('true', $action['transfer']);

        $temp = new FunctionResult();
        $temp->connect('+15551234567', false);
        $this->assertSame('false', $temp->toArray()['action'][0]['transfer']);
    }

    // swmlTransfer parity with Python swml_transfer: builds a SWML document
    // {set:{ai_response}, transfer:{dest}} and a top-level transfer="true"/
    // "false" (final defaults TRUE). The invented bare transfer_uri action is
    // gone; the ai_response goes INTO the SWML set verb, not onto response.

    public function testSwmlTransferBasicDefaultsFinalTrue(): void
    {
        $fr = new FunctionResult();
        $fr->swmlTransfer('https://example.com/swml', 'Goodbye!');
        $action = $fr->toArray()['action'][0];

        $main = $action['SWML']['sections']['main'];
        $this->assertSame('1.0.0', $action['SWML']['version']);
        $this->assertSame(['ai_response' => 'Goodbye!'], $main[0]['set']);
        $this->assertSame(['dest' => 'https://example.com/swml'], $main[1]['transfer']);
        // final defaults TRUE -> permanent transfer.
        $this->assertSame('true', $action['transfer']);
    }

    public function testSwmlTransferFinalFalse(): void
    {
        $fr = new FunctionResult();
        $fr->swmlTransfer('https://example.com/swml', 'Back to you', false);
        $action = $fr->toArray()['action'][0];

        $this->assertSame('false', $action['transfer']);
        $main = $action['SWML']['sections']['main'];
        $this->assertSame(['ai_response' => 'Back to you'], $main[0]['set']);
        $this->assertSame(['dest' => 'https://example.com/swml'], $main[1]['transfer']);
    }

    public function testSwmlTransferAiResponseGoesIntoSwmlNotResponse(): void
    {
        // The ai_response is set inside the SWML `set` verb; it must NOT
        // overwrite the FunctionResult's top-level response text.
        $fr = new FunctionResult('original');
        $fr->swmlTransfer('https://example.com/swml', 'Transferring now');
        $arr = $fr->toArray();

        $this->assertSame('original', $arr['response']);
        $this->assertSame(
            ['ai_response' => 'Transferring now'],
            $arr['action'][0]['SWML']['sections']['main'][0]['set']
        );
    }

    public function testHangup(): void
    {
        // Python: add_action("hangup", True) -> {"hangup": true}.
        $fr = new FunctionResult();
        $fr->hangup();
        $action = $fr->toArray()['action'][0];

        $this->assertTrue($action['hangup']);
    }

    public function testHoldDefault(): void
    {
        // Python emits a bare int, not {"timeout": N}.
        $fr = new FunctionResult();
        $fr->hold();
        $this->assertSame(300, $fr->toArray()['action'][0]['hold']);
    }

    public function testHoldClampsLow(): void
    {
        $fr = new FunctionResult();
        $fr->hold(-50);
        $this->assertSame(0, $fr->toArray()['action'][0]['hold']);
    }

    public function testHoldClampsHigh(): void
    {
        $fr = new FunctionResult();
        $fr->hold(9999);
        $this->assertSame(900, $fr->toArray()['action'][0]['hold']);
    }

    public function testHoldWithinRange(): void
    {
        $fr = new FunctionResult();
        $fr->hold(450);
        $this->assertSame(450, $fr->toArray()['action'][0]['hold']);
    }

    // waitForUser parity: Python emits a SCALAR value (string/int/bool), never
    // an object. Precedence: answer_first ("answer_first") > timeout (int) >
    // enabled (bool) > true.

    public function testWaitForUserNoParamsEmitsTrue(): void
    {
        $fr = new FunctionResult();
        $fr->waitForUser();
        $this->assertTrue($fr->toArray()['action'][0]['wait_for_user']);
    }

    public function testWaitForUserAnswerFirstWins(): void
    {
        $fr = new FunctionResult();
        // answer_first takes precedence over both timeout and enabled.
        $fr->waitForUser(true, 30, true);
        $this->assertSame('answer_first', $fr->toArray()['action'][0]['wait_for_user']);
    }

    public function testWaitForUserTimeoutEmitsInt(): void
    {
        $fr = new FunctionResult();
        $fr->waitForUser(null, 60);
        $this->assertSame(60, $fr->toArray()['action'][0]['wait_for_user']);
    }

    public function testWaitForUserEnabledEmitsBool(): void
    {
        $fr = new FunctionResult();
        $fr->waitForUser(false);
        $this->assertFalse($fr->toArray()['action'][0]['wait_for_user']);
    }

    public function testStop(): void
    {
        $fr = new FunctionResult();
        $fr->stop();
        $this->assertTrue($fr->toArray()['action'][0]['stop']);
    }

    // ── State & Data ──────────────────────────────────────────────────────

    public function testUpdateGlobalData(): void
    {
        $fr = new FunctionResult();
        $fr->updateGlobalData(['key' => 'value']);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['key' => 'value'], $action['set_global_data']);
    }

    public function testRemoveGlobalDataList(): void
    {
        // Python action name is "unset_global_data"; the value is emitted
        // directly (no {"keys": ...} wrapper).
        $fr = new FunctionResult();
        $fr->removeGlobalData(['k1', 'k2']);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['k1', 'k2'], $action['unset_global_data']);
        $this->assertArrayNotHasKey('remove_global_data', $action);
    }

    public function testRemoveGlobalDataSingleString(): void
    {
        // Python accepts Union[str, List[str]] and emits a string as-is.
        $fr = new FunctionResult();
        $fr->removeGlobalData('mykey');
        $this->assertSame('mykey', $fr->toArray()['action'][0]['unset_global_data']);
    }

    public function testSetMetadata(): void
    {
        $fr = new FunctionResult();
        $fr->setMetadata(['foo' => 'bar']);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['foo' => 'bar'], $action['set_meta_data']);
    }

    public function testRemoveMetadata(): void
    {
        // Python action name is "unset_meta_data"; value emitted directly.
        $fr = new FunctionResult();
        $fr->removeMetadata(['x', 'y']);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['x', 'y'], $action['unset_meta_data']);
        $this->assertArrayNotHasKey('remove_meta_data', $action);
    }

    public function testSwmlUserEvent(): void
    {
        // Python wraps the event in a SWML document, nesting the data under
        // user_event.event. Key order is {sections, version} (sections first).
        $fr = new FunctionResult();
        $fr->swmlUserEvent(['type' => 'custom', 'data' => 123]);
        $action = $fr->toArray()['action'][0];

        $this->assertSame('1.0.0', $action['SWML']['version']);
        $userEvent = $action['SWML']['sections']['main'][0]['user_event'];
        $this->assertSame(['event' => ['type' => 'custom', 'data' => 123]], $userEvent);
    }

    public function testSwmlChangeStep(): void
    {
        // Python: add_action("change_step", step_name) -> bare string.
        $fr = new FunctionResult();
        $fr->swmlChangeStep('greeting');
        $action = $fr->toArray()['action'][0];

        $this->assertSame('greeting', $action['change_step']);
        $this->assertArrayNotHasKey('context_switch', $action);
    }

    public function testSwmlChangeContext(): void
    {
        // Python: add_action("change_context", context_name) -> bare string.
        $fr = new FunctionResult();
        $fr->swmlChangeContext('billing');
        $action = $fr->toArray()['action'][0];

        $this->assertSame('billing', $action['change_context']);
        $this->assertArrayNotHasKey('context_switch', $action);
    }

    public function testSwitchContextSimple(): void
    {
        // Python: when ONLY system_prompt is supplied (no user_prompt /
        // consolidate / full_reset / isolated), the action value is the BARE
        // system-prompt STRING ({"context_switch": "<prompt>"}), not an object.
        // Parity with function_result.py:switch_context's simple branch and the
        // verified Go/Rust siblings.
        $fr = new FunctionResult();
        $fr->switchContext('You are a helpful agent.');
        $cs = $fr->toArray()['action'][0]['context_switch'];

        $this->assertSame('You are a helpful agent.', $cs);
        // Bare string on the wire, not an object.
        $this->assertStringContainsString(
            '"context_switch":"You are a helpful agent."',
            $fr->toJson()
        );
    }

    public function testSwitchContextFull(): void
    {
        $fr = new FunctionResult();
        $fr->switchContext('sys', 'usr', true, true, true);
        $cs = $fr->toArray()['action'][0]['context_switch'];

        $this->assertSame('sys', $cs['system_prompt']);
        $this->assertSame('usr', $cs['user_prompt']);
        $this->assertTrue($cs['consolidate']);
        $this->assertTrue($cs['full_reset']);
        $this->assertTrue($cs['isolated']);
    }

    public function testSwitchContextNonDefaultFlagPromotesToObjectForm(): void
    {
        // The simple-string branch only fires when system_prompt is the ONLY
        // thing set. Any non-default flag (here this port's `isolated` extension)
        // promotes to the object form with system_prompt present — it must NOT
        // collapse to a bare string. Parity with Go/Rust's documented `isolated`
        // handling (object branch).
        $fr = new FunctionResult();
        $fr->switchContext('sys', '', false, false, true);
        $cs = $fr->toArray()['action'][0]['context_switch'];

        $this->assertIsArray($cs);
        $this->assertSame('sys', $cs['system_prompt']);
        $this->assertTrue($cs['isolated']);
        $this->assertArrayNotHasKey('user_prompt', $cs);
    }

    public function testReplaceInHistoryWithString(): void
    {
        // Python action name is "replace_in_history"; the string is emitted
        // verbatim (not converted to "summary").
        $fr = new FunctionResult();
        $fr->replaceInHistory('redacted');
        $action = $fr->toArray()['action'][0];
        $this->assertSame('redacted', $action['replace_in_history']);
        $this->assertArrayNotHasKey('replace_history', $action);
    }

    public function testReplaceInHistoryDefaultTrue(): void
    {
        // Default is the literal boolean true (remove the pair entirely).
        $fr = new FunctionResult();
        $fr->replaceInHistory();
        $this->assertTrue($fr->toArray()['action'][0]['replace_in_history']);
    }

    // ── Media ─────────────────────────────────────────────────────────────

    public function testSay(): void
    {
        $fr = new FunctionResult();
        $fr->say('Hello world');
        $this->assertSame('Hello world', $fr->toArray()['action'][0]['say']);
    }

    public function testPlayBackgroundFileDefault(): void
    {
        // Python action name is "playback_bg"; bare filename without wait.
        $fr = new FunctionResult();
        $fr->playBackgroundFile('music.mp3');
        $action = $fr->toArray()['action'][0];

        $this->assertSame('music.mp3', $action['playback_bg']);
    }

    public function testPlayBackgroundFileWithWait(): void
    {
        // With wait, the value is an object {file, wait:true}.
        $fr = new FunctionResult();
        $fr->playBackgroundFile('music.mp3', true);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['file' => 'music.mp3', 'wait' => true], $action['playback_bg']);
    }

    public function testStopBackgroundFile(): void
    {
        // Python action name is "stop_playback_bg".
        $fr = new FunctionResult();
        $fr->stopBackgroundFile();
        $this->assertTrue($fr->toArray()['action'][0]['stop_playback_bg']);
    }

    // recordCall parity: the {"record_call": ...} verb is wrapped in a full
    // SWML document (via executeSwml, lands under "SWML"). stereo/format/
    // direction/beep/input_sensitivity are ALWAYS emitted; the invented
    // "initiator" field is gone.

    public function testRecordCallDefaults(): void
    {
        $fr = new FunctionResult();
        $fr->recordCall();
        $rec = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['record_call'];

        $this->assertFalse($rec['stereo']);
        $this->assertSame('wav', $rec['format']);
        $this->assertSame('both', $rec['direction']);
        $this->assertFalse($rec['beep']);
        $this->assertSame(44.0, $rec['input_sensitivity']);
        $this->assertArrayNotHasKey('control_id', $rec);
        $this->assertArrayNotHasKey('initiator', $rec);
    }

    public function testRecordCallWithAllParams(): void
    {
        $fr = new FunctionResult();
        $fr->recordCall('rec-1', true, 'mp3', 'speak', '#', true, 30.0, 5.0, 2.0, 60.0, 'https://example.com/status');
        $rec = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['record_call'];

        $this->assertSame('rec-1', $rec['control_id']);
        $this->assertTrue($rec['stereo']);
        $this->assertSame('mp3', $rec['format']);
        $this->assertSame('speak', $rec['direction']);
        $this->assertTrue($rec['beep']);
        $this->assertSame(30.0, $rec['input_sensitivity']);
        $this->assertSame('#', $rec['terminators']);
        $this->assertSame(5.0, $rec['initial_timeout']);
        $this->assertSame(2.0, $rec['end_silence_timeout']);
        $this->assertSame(60.0, $rec['max_length']);
        $this->assertSame('https://example.com/status', $rec['status_url']);
    }

    public function testRecordCallInvalidFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("format must be 'wav', 'mp3', or 'mp4'");
        (new FunctionResult())->recordCall('id', false, 'aac');
    }

    public function testRecordCallInvalidDirectionThrows(): void
    {
        // record_call rejects 'hear' (that belongs to tap's set, not this one).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("direction must be 'speak', 'listen', or 'both'");
        (new FunctionResult())->recordCall('id', false, 'wav', 'hear');
    }

    public function testStopRecordCallWithoutControlId(): void
    {
        // SWML-wrapped; empty object ({}) when no control_id.
        $fr = new FunctionResult();
        $fr->stopRecordCall();
        $stop = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['stop_record_call'];

        $this->assertSame('{}', json_encode($stop));
    }

    public function testStopRecordCallWithControlId(): void
    {
        $fr = new FunctionResult();
        $fr->stopRecordCall('rec-1');
        $stop = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['stop_record_call'];

        $this->assertSame(['control_id' => 'rec-1'], $stop);
    }

    // ── Speech & AI ───────────────────────────────────────────────────────

    public function testAddDynamicHints(): void
    {
        $fr = new FunctionResult();
        $fr->addDynamicHints(['yes', 'no', 'maybe']);
        $this->assertSame(['yes', 'no', 'maybe'], $fr->toArray()['action'][0]['add_dynamic_hints']);
    }

    public function testClearDynamicHints(): void
    {
        // Python (function_result.py): {"clear_dynamic_hints": {}} — the value is
        // an empty OBJECT, not a boolean. Every other port emits {} too. Assert on
        // the encoded JSON string: an empty stdClass renders as {}, whereas the
        // old buggy `true` (or a PHP empty array []) would NOT contain "{}".
        $fr = new FunctionResult();
        $fr->clearDynamicHints();

        $value = $fr->toArray()['action'][0]['clear_dynamic_hints'];
        $this->assertInstanceOf(\stdClass::class, $value);
        // On the wire the action is a JSON object, NOT a boolean and NOT [].
        $this->assertStringContainsString('"clear_dynamic_hints":{}', $fr->toJson());
        $this->assertStringNotContainsString('"clear_dynamic_hints":true', $fr->toJson());
        $this->assertStringNotContainsString('"clear_dynamic_hints":[]', $fr->toJson());
    }

    public function testSetEndOfSpeechTimeout(): void
    {
        $fr = new FunctionResult();
        $fr->setEndOfSpeechTimeout(500);
        $this->assertSame(500, $fr->toArray()['action'][0]['end_of_speech_timeout']);
    }

    public function testSetSpeechEventTimeout(): void
    {
        $fr = new FunctionResult();
        $fr->setSpeechEventTimeout(1000);
        $this->assertSame(1000, $fr->toArray()['action'][0]['speech_event_timeout']);
    }

    public function testToggleFunctions(): void
    {
        $fr = new FunctionResult();
        $fr->toggleFunctions(['lookup' => true, 'transfer' => false]);
        $toggled = $fr->toArray()['action'][0]['toggle_functions'];

        $this->assertCount(2, $toggled);
        $this->assertSame(['function' => 'lookup', 'active' => true], $toggled[0]);
        $this->assertSame(['function' => 'transfer', 'active' => false], $toggled[1]);
    }

    public function testEnableFunctionsOnTimeoutDefault(): void
    {
        // Python action name is "functions_on_speaker_timeout".
        $fr = new FunctionResult();
        $fr->enableFunctionsOnTimeout();
        $action = $fr->toArray()['action'][0];
        $this->assertTrue($action['functions_on_speaker_timeout']);
        $this->assertArrayNotHasKey('functions_on_timeout', $action);
    }

    public function testEnableFunctionsOnTimeoutFalse(): void
    {
        $fr = new FunctionResult();
        $fr->enableFunctionsOnTimeout(false);
        $this->assertFalse($fr->toArray()['action'][0]['functions_on_speaker_timeout']);
    }

    public function testEnableExtensiveDataDefault(): void
    {
        $fr = new FunctionResult();
        $fr->enableExtensiveData();
        $this->assertTrue($fr->toArray()['action'][0]['extensive_data']);
    }

    public function testEnableExtensiveDataFalse(): void
    {
        $fr = new FunctionResult();
        $fr->enableExtensiveData(false);
        $this->assertFalse($fr->toArray()['action'][0]['extensive_data']);
    }

    public function testUpdateSettings(): void
    {
        // Python action name is "settings" (not "ai_settings").
        $fr = new FunctionResult();
        $fr->updateSettings(['temperature' => 0.7, 'top_p' => 0.9]);
        $action = $fr->toArray()['action'][0];
        $this->assertSame(['temperature' => 0.7, 'top_p' => 0.9], $action['settings']);
        $this->assertArrayNotHasKey('ai_settings', $action);
    }

    // ── Advanced ──────────────────────────────────────────────────────────

    public function testExecuteSwmlWithArray(): void
    {
        $swml = ['sections' => ['main' => [['answer' => new \stdClass()]]]];
        $fr = new FunctionResult();
        $fr->executeSwml($swml);
        $action = $fr->toArray()['action'][0];

        $this->assertSame($swml, $action['SWML']);
        $this->assertArrayNotHasKey('transfer_swml', $action);
    }

    public function testExecuteSwmlWithString(): void
    {
        // A SWML string is decoded preserving OBJECTS: a nested empty object {}
        // must stay {} on the wire, NOT collapse to [] (which json_decode(...,
        // true) would do). Python's json.loads keeps it a dict; Go/Rust keep it
        // an object. Assert on the encoded JSON so the {}-vs-[] distinction is
        // visible (a PHP empty array would render as []).
        $swmlJson = '{"sections":{"main":[{"answer":{}}]}}';
        $fr = new FunctionResult();
        $fr->executeSwml($swmlJson);

        $this->assertSame(
            '{"sections":{"main":[{"answer":{}}]}}',
            json_encode($fr->toArray()['action'][0]['SWML'])
        );
    }

    public function testExecuteSwmlWithTransfer(): void
    {
        // Python sets transfer="true" INSIDE the SWML document and still adds
        // it under the "SWML" action key (no separate transfer_swml key).
        $swml = ['sections' => ['main' => [['answer' => []]]]];
        $fr = new FunctionResult();
        $fr->executeSwml($swml, true);
        $action = $fr->toArray()['action'][0];

        $this->assertArrayNotHasKey('transfer_swml', $action);
        $this->assertSame('true', $action['SWML']['transfer']);
        $this->assertSame($swml['sections'], $action['SWML']['sections']);
    }

    public function testExecuteSwmlInvalidStringFallsBackToRawSwml(): void
    {
        // Parity: a non-JSON string is wrapped as {"raw_swml": <text>}.
        $fr = new FunctionResult();
        $fr->executeSwml('not valid json at all');
        $this->assertSame(
            ['raw_swml' => 'not valid json at all'],
            $fr->toArray()['action'][0]['SWML']
        );
    }

    public function testExecuteSwmlStringWithTransferPreservesEmptyObject(): void
    {
        // String + transfer: the nested empty object {} must be preserved AND
        // transfer="true" set as a sibling INSIDE the SWML doc. Byte-identical to
        // Python execute_swml('{"sections":{"main":[{"answer":{}}]}}', True) ->
        // {"SWML":{"sections":{"main":[{"answer":{}}]},"transfer":"true"}}.
        $fr = new FunctionResult();
        $fr->executeSwml('{"sections":{"main":[{"answer":{}}]}}', true);

        $this->assertSame(
            '{"SWML":{"sections":{"main":[{"answer":{}}]},"transfer":"true"}}',
            json_encode($fr->toArray()['action'][0])
        );
    }

    public function testExecuteSwmlInvalidStringWithTransfer(): void
    {
        // raw_swml fallback still injects transfer="true" as a sibling key.
        // Parity: {"SWML":{"raw_swml":"not json","transfer":"true"}}.
        $fr = new FunctionResult();
        $fr->executeSwml('not json', true);
        $this->assertSame(
            ['raw_swml' => 'not json', 'transfer' => 'true'],
            $fr->toArray()['action'][0]['SWML']
        );
    }

    // ── joinConference ────────────────────────────────────────────────────
    //
    // Parity with signalwire-python core/function_result.py join_conference.
    // The reference wraps {"join_conference": ...} in a full SWML document and
    // emits it through execute_swml (the same path record_call uses), so the
    // payload lands under the "SWML" action key, NOT a bare "join_conference".
    // All-defaults collapses to the bare conference-NAME string; any non-default
    // promotes to the object form keyed by snake_case wire keys. Mirrors
    // tests/unit/core/test_function_result.py::TestJoinConference. No mocks —
    // assertions read the real built SWML action payload.

    public function testJoinConferenceSimpleNameAllDefaults(): void
    {
        // Parity: test_join_conference_simple_name_all_defaults
        $result = (new FunctionResult())->joinConference('my-conference');

        $swml = $result->toArray()['action'][0]['SWML'];
        $joinParams = $swml['sections']['main'][0]['join_conference'];
        // Simple form: just the conference name string.
        $this->assertSame('my-conference', $joinParams);
    }

    public function testJoinConferenceComplexParams(): void
    {
        // Parity: test_join_conference_complex_params
        $result = (new FunctionResult())->joinConference(
            'team-meeting',
            true,                                       // muted
            'onEnter',                                  // beep
            false,                                      // startOnEnter
            true,                                       // endOnExit
            'https://example.com/hold-music',           // waitUrl
            50,                                         // maxParticipants
            'record-from-start',                        // record
            'us-east',                                  // region
            'do-not-trim',                              // trim
            'call-id-123',                              // coach
            'start end',                                // statusCallbackEvent
            'https://example.com/callback',             // statusCallback
            'GET',                                      // statusCallbackMethod
            'https://example.com/rec-callback',         // recordingStatusCallback
            'GET',                                      // recordingStatusCallbackMethod
            'in-progress',                              // recordingStatusCallbackEvent
            ['key' => 'value']                          // result
        );

        $swml = $result->toArray()['action'][0]['SWML'];
        $joinParams = $swml['sections']['main'][0]['join_conference'];
        $this->assertIsArray($joinParams);
        $this->assertSame('team-meeting', $joinParams['name']);
        $this->assertTrue($joinParams['muted']);
        $this->assertSame('onEnter', $joinParams['beep']);
        $this->assertFalse($joinParams['start_on_enter']);
        $this->assertTrue($joinParams['end_on_exit']);
        $this->assertSame('https://example.com/hold-music', $joinParams['wait_url']);
        $this->assertSame(50, $joinParams['max_participants']);
        $this->assertSame('record-from-start', $joinParams['record']);
        $this->assertSame('us-east', $joinParams['region']);
        $this->assertSame('do-not-trim', $joinParams['trim']);
        $this->assertSame('call-id-123', $joinParams['coach']);
        $this->assertSame('start end', $joinParams['status_callback_event']);
        $this->assertSame('https://example.com/callback', $joinParams['status_callback']);
        $this->assertSame('GET', $joinParams['status_callback_method']);
        $this->assertSame('https://example.com/rec-callback', $joinParams['recording_status_callback']);
        $this->assertSame('GET', $joinParams['recording_status_callback_method']);
        $this->assertSame('in-progress', $joinParams['recording_status_callback_event']);
        $this->assertSame(['key' => 'value'], $joinParams['result']);
    }

    public function testJoinConferenceInvalidBeep(): void
    {
        // Parity: test_join_conference_invalid_beep
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('beep must be one of');
        (new FunctionResult())->joinConference('conf', false, 'invalid');
    }

    public function testJoinConferenceMaxParticipantsTooHigh(): void
    {
        // Parity: test_join_conference_max_participants_too_high
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_participants must be a positive integer <= 250');
        (new FunctionResult())->joinConference('conf', false, 'true', true, false, null, 300);
    }

    public function testJoinConferenceMaxParticipantsZero(): void
    {
        // Parity: test_join_conference_max_participants_zero
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_participants must be a positive integer <= 250');
        (new FunctionResult())->joinConference('conf', false, 'true', true, false, null, 0);
    }

    public function testJoinConferenceMaxParticipantsNegative(): void
    {
        // Parity: test_join_conference_max_participants_negative
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_participants must be a positive integer <= 250');
        (new FunctionResult())->joinConference('conf', false, 'true', true, false, null, -5);
    }

    public function testJoinConferenceInvalidRecord(): void
    {
        // Parity: test_join_conference_invalid_record
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('record must be one of');
        (new FunctionResult())->joinConference('conf', false, 'true', true, false, null, 250, 'always');
    }

    public function testJoinConferenceInvalidTrim(): void
    {
        // Parity: test_join_conference_invalid_trim
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trim must be one of');
        (new FunctionResult())->joinConference(
            'conf', false, 'true', true, false, null, 250, 'do-not-record', null, 'bad-value'
        );
    }

    public function testJoinConferenceEmptyName(): void
    {
        // Parity: test_join_conference_empty_name
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name cannot be empty');
        (new FunctionResult())->joinConference('', true);
    }

    public function testJoinConferenceWhitespaceName(): void
    {
        // Parity: test_join_conference_whitespace_name
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name cannot be empty');
        (new FunctionResult())->joinConference('   ', true);
    }

    public function testJoinConferenceInvalidStatusCallbackMethod(): void
    {
        // Parity: test_join_conference_invalid_status_callback_method
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('status_callback_method must be one of');
        (new FunctionResult())->joinConference(
            'conf', false, 'true', true, false, null, 250, 'do-not-record', null,
            'trim-silence', null, null, null, 'PUT'
        );
    }

    public function testJoinConferenceInvalidRecordingStatusCallbackMethod(): void
    {
        // Parity: test_join_conference_invalid_recording_status_callback_method
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recording_status_callback_method must be one of');
        (new FunctionResult())->joinConference(
            'conf', false, 'true', true, false, null, 250, 'do-not-record', null,
            'trim-silence', null, null, null, 'POST', null, 'DELETE'
        );
    }

    public function testJoinConferenceChaining(): void
    {
        // Parity: test_join_conference_chaining
        $result = new FunctionResult();
        $ret = $result->joinConference('conf');
        $this->assertSame($result, $ret);
    }

    public function testJoinConferenceExactBeepMessageRendersPythonList(): void
    {
        // The reference renders the valid set with Python list repr, e.g.
        // beep must be one of ['true', 'false', 'onEnter', 'onExit'].
        // Mirror that exact rendering so a port user sees the same message.
        try {
            (new FunctionResult())->joinConference('conf', false, 'nope');
            $this->fail('expected InvalidArgumentException for invalid beep');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                "beep must be one of ['true', 'false', 'onEnter', 'onExit']",
                $e->getMessage()
            );
        }
    }

    public function testJoinRoom(): void
    {
        // Python wraps {"join_room": {name}} in a full SWML document.
        $fr = new FunctionResult();
        $fr->joinRoom('video-room');
        $joinRoom = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['join_room'];
        $this->assertSame(['name' => 'video-room'], $joinRoom);
    }

    public function testSipRefer(): void
    {
        // Python wraps {"sip_refer": {to_uri}} in a full SWML document.
        $fr = new FunctionResult();
        $fr->sipRefer('sip:agent@example.com');
        $sipRefer = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['sip_refer'];
        $this->assertSame(['to_uri' => 'sip:agent@example.com'], $sipRefer);
    }

    // tap parity: the {"tap": ...} verb is SWML-wrapped (executeSwml -> "SWML").
    // Only `uri` is always present; control_id/direction/codec/rtp_ptime/
    // status_url are emitted only when they differ from their defaults.

    public function testTapBasicOmitsDefaults(): void
    {
        $fr = new FunctionResult();
        $fr->tap('wss://tap.example.com');
        $t = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['tap'];

        $this->assertSame('wss://tap.example.com', $t['uri']);
        // direction/codec/rtp_ptime/status_url are at defaults -> omitted.
        $this->assertArrayNotHasKey('direction', $t);
        $this->assertArrayNotHasKey('codec', $t);
        $this->assertArrayNotHasKey('rtp_ptime', $t);
        $this->assertArrayNotHasKey('control_id', $t);
        $this->assertArrayNotHasKey('status_url', $t);
    }

    public function testTapWithAllParams(): void
    {
        $fr = new FunctionResult();
        $fr->tap('wss://tap.example.com', 'tap-1', 'speak', 'PCMA', 40, 'https://example.com/status');
        $t = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['tap'];

        $this->assertSame('tap-1', $t['control_id']);
        $this->assertSame('speak', $t['direction']);
        $this->assertSame('PCMA', $t['codec']);
        $this->assertSame(40, $t['rtp_ptime']);
        $this->assertSame('https://example.com/status', $t['status_url']);
    }

    public function testTapInvalidDirectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('direction must be one of');
        (new FunctionResult())->tap('wss://t', null, 'listen'); // 'listen' is record_call's, not tap's
    }

    public function testTapInvalidCodecThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('codec must be one of');
        (new FunctionResult())->tap('wss://t', null, 'both', 'OPUS');
    }

    public function testTapInvalidRtpPtimeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rtp_ptime must be a positive integer');
        (new FunctionResult())->tap('wss://t', null, 'both', 'PCMU', 0);
    }

    public function testStopTapWithoutControlId(): void
    {
        $fr = new FunctionResult();
        $fr->stopTap();
        $stop = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['stop_tap'];
        $this->assertSame('{}', json_encode($stop));
    }

    public function testStopTapWithControlId(): void
    {
        $fr = new FunctionResult();
        $fr->stopTap('tap-1');
        $stop = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['stop_tap'];
        $this->assertSame(['control_id' => 'tap-1'], $stop);
    }

    // sendSms parity: the {"send_sms": ...} verb is SWML-wrapped (executeSwml
    // -> "SWML"); to_number/from_number always present; body/media/tags/region
    // emitted only when supplied; raises if neither body nor media is given.

    public function testSendSmsBasic(): void
    {
        $fr = new FunctionResult();
        $fr->sendSms('+15551111111', '+15552222222', 'Hello');
        $sms = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['send_sms'];

        $this->assertSame('+15551111111', $sms['to_number']);
        $this->assertSame('+15552222222', $sms['from_number']);
        $this->assertSame('Hello', $sms['body']);
        $this->assertArrayNotHasKey('media', $sms);
        $this->assertArrayNotHasKey('tags', $sms);
        $this->assertArrayNotHasKey('region', $sms);
    }

    public function testSendSmsWithMediaTagsAndRegion(): void
    {
        $fr = new FunctionResult();
        $fr->sendSms(
            '+15551111111',
            '+15552222222',
            'See image',
            ['https://example.com/img.png'],
            ['campaign' => 'promo'],
            'us'
        );
        $sms = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['send_sms'];

        $this->assertSame(['https://example.com/img.png'], $sms['media']);
        $this->assertSame(['campaign' => 'promo'], $sms['tags']);
        $this->assertSame('us', $sms['region']);
    }

    public function testSendSmsMediaOnlyNoBody(): void
    {
        // body is optional when media is provided.
        $fr = new FunctionResult();
        $fr->sendSms('+15551111111', '+15552222222', null, ['https://example.com/img.png']);
        $sms = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['send_sms'];

        $this->assertArrayNotHasKey('body', $sms);
        $this->assertSame(['https://example.com/img.png'], $sms['media']);
    }

    public function testSendSmsNeitherBodyNorMediaThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Either body or media must be provided');
        (new FunctionResult())->sendSms('+15551111111', '+15552222222');
    }

    // pay parity: the {"pay": ...} verb is SWML-wrapped, preceded by a
    // {set:{ai_response}} verb. The collection-method wire key is `input`
    // (NOT input_method); numbers and booleans are stringified; the invented
    // `action_url` is gone (the status URL is `status_url`).

    public function testPayBasicDefaults(): void
    {
        $fr = new FunctionResult();
        $fr->pay('https://pay.example.com/connector');
        $main = $fr->toArray()['action'][0]['SWML']['sections']['main'];

        // First verb sets the default ai_response.
        $this->assertStringStartsWith('The payment status is', $main[0]['set']['ai_response']);

        $p = $main[1]['pay'];
        $this->assertSame('https://pay.example.com/connector', $p['payment_connector_url']);
        $this->assertSame('dtmf', $p['input']);                 // wire key is `input`
        $this->assertSame('credit-card', $p['payment_method']);
        $this->assertSame('5', $p['timeout']);                  // stringified
        $this->assertSame('1', $p['max_attempts']);
        $this->assertSame('true', $p['security_code']);
        $this->assertSame('0', $p['min_postal_code_length']);
        $this->assertSame('reusable', $p['token_type']);
        $this->assertSame('usd', $p['currency']);
        $this->assertSame('en-US', $p['language']);
        $this->assertSame('woman', $p['voice']);
        $this->assertSame('visa mastercard amex', $p['valid_card_types']);
        $this->assertSame('true', $p['postal_code']);           // bool -> "true"
        // Optionals absent at default.
        $this->assertArrayNotHasKey('status_url', $p);
        $this->assertArrayNotHasKey('charge_amount', $p);
        $this->assertArrayNotHasKey('input_method', $p);        // invented key gone
        $this->assertArrayNotHasKey('action_url', $p);          // invented key gone
    }

    public function testPayWithAllOptions(): void
    {
        $fr = new FunctionResult();
        $fr->pay(
            'https://pay.example.com/connector',
            'dtmf',
            'https://pay.example.com/status',
            'credit-card',
            120,
            5,
            false,
            '94105',          // postal_code as a string
            5,
            'one-time',
            '19.99',
            'eur',
            'es-MX',
            'man',
            'Premium plan',
            'visa mastercard',
            [['name' => 'order', 'value' => '42']],
            [['for' => 'payment-card-number', 'actions' => []]],
            'Custom response'
        );
        $p = $fr->toArray()['action'][0]['SWML']['sections']['main'][1]['pay'];

        $this->assertSame('120', $p['timeout']);
        $this->assertSame('5', $p['max_attempts']);
        $this->assertSame('false', $p['security_code']);
        $this->assertSame('94105', $p['postal_code']);          // string verbatim
        $this->assertSame('5', $p['min_postal_code_length']);
        $this->assertSame('one-time', $p['token_type']);
        $this->assertSame('19.99', $p['charge_amount']);
        $this->assertSame('eur', $p['currency']);
        $this->assertSame('es-MX', $p['language']);
        $this->assertSame('man', $p['voice']);
        $this->assertSame('Premium plan', $p['description']);
        $this->assertSame('visa mastercard', $p['valid_card_types']);
        $this->assertSame('https://pay.example.com/status', $p['status_url']);
        $this->assertSame([['name' => 'order', 'value' => '42']], $p['parameters']);
        $this->assertSame([['for' => 'payment-card-number', 'actions' => []]], $p['prompts']);
        // Custom ai_response overrides the default.
        $set = $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['set'];
        $this->assertSame('Custom response', $set['ai_response']);
    }

    // ── RPC ───────────────────────────────────────────────────────────────

    // execute_rpc parity: the {"execute_rpc": ...} verb is SWML-wrapped; there
    // is NO jsonrpc envelope; method strings are bare (e.g. "dial", not
    // "calling.dial"); call_id/node_id are TOP-LEVEL siblings of method/params.

    /** Reach into the SWML-wrapped execute_rpc params. */
    private static function rpcOf(FunctionResult $fr): array
    {
        return $fr->toArray()['action'][0]['SWML']['sections']['main'][0]['execute_rpc'];
    }

    public function testExecuteRpcMethodOnly(): void
    {
        $fr = new FunctionResult();
        $fr->executeRpc('ai_unhold');
        $rpc = self::rpcOf($fr);

        $this->assertSame('ai_unhold', $rpc['method']);
        $this->assertArrayNotHasKey('jsonrpc', $rpc);
        $this->assertArrayNotHasKey('params', $rpc);
        $this->assertArrayNotHasKey('call_id', $rpc);
        $this->assertArrayNotHasKey('node_id', $rpc);
    }

    public function testExecuteRpcWithCallIdNodeIdAndParams(): void
    {
        $fr = new FunctionResult();
        $fr->executeRpc('ai_message', ['role' => 'system', 'message_text' => 'hi'], 'c1', 'n1');
        $rpc = self::rpcOf($fr);

        $this->assertSame('ai_message', $rpc['method']);
        // call_id / node_id are siblings of method, NOT nested in params.
        $this->assertSame('c1', $rpc['call_id']);
        $this->assertSame('n1', $rpc['node_id']);
        $this->assertSame(['role' => 'system', 'message_text' => 'hi'], $rpc['params']);
    }

    public function testRpcDialNestsDevices(): void
    {
        $fr = new FunctionResult();
        $fr->rpcDial('+15551234567', '+15559876543', 'https://example.com/swml');
        $rpc = self::rpcOf($fr);

        $this->assertSame('dial', $rpc['method']);
        $this->assertArrayNotHasKey('jsonrpc', $rpc);
        $this->assertSame(
            [
                'devices' => [
                    'type' => 'phone',
                    'params' => [
                        'to_number' => '+15551234567',
                        'from_number' => '+15559876543',
                    ],
                ],
                'dest_swml' => 'https://example.com/swml',
            ],
            $rpc['params']
        );
    }

    public function testRpcDialCustomDeviceType(): void
    {
        $fr = new FunctionResult();
        $fr->rpcDial('+1', '+2', 'https://swml', 'sip');
        $rpc = self::rpcOf($fr);
        $this->assertSame('sip', $rpc['params']['devices']['type']);
    }

    public function testRpcAiMessage(): void
    {
        $fr = new FunctionResult();
        $fr->rpcAiMessage('call-abc-123', 'Please hold');
        $rpc = self::rpcOf($fr);

        $this->assertSame('ai_message', $rpc['method']);
        $this->assertSame('call-abc-123', $rpc['call_id']);   // top-level
        $this->assertSame('system', $rpc['params']['role']);  // default role
        $this->assertSame('Please hold', $rpc['params']['message_text']);
        $this->assertArrayNotHasKey('call_id', $rpc['params']);
    }

    public function testRpcAiMessageCustomRole(): void
    {
        $fr = new FunctionResult();
        $fr->rpcAiMessage('c1', 'msg', 'user');
        $rpc = self::rpcOf($fr);
        $this->assertSame('user', $rpc['params']['role']);
    }

    public function testRpcAiUnhold(): void
    {
        $fr = new FunctionResult();
        $fr->rpcAiUnhold('call-xyz-789');
        $rpc = self::rpcOf($fr);

        $this->assertSame('ai_unhold', $rpc['method']);
        $this->assertSame('call-xyz-789', $rpc['call_id']);   // top-level
        // Empty params dict is dropped (Python's `if params:` is falsy for {}).
        $this->assertArrayNotHasKey('params', $rpc);
    }

    public function testSimulateUserInput(): void
    {
        // Python action name is "user_input".
        $fr = new FunctionResult();
        $fr->simulateUserInput('I need help');
        $action = $fr->toArray()['action'][0];
        $this->assertSame('I need help', $action['user_input']);
        $this->assertArrayNotHasKey('simulate_user_input', $action);
    }

    // ── Payment Helpers (static) ──────────────────────────────────────────

    // Static payment-helper parity with Python:
    //   create_payment_prompt(for_situation, actions, card_type?, error_type?)
    //     -> {"for":..., "actions":..., "card_type"?, "error_type"?}
    //   create_payment_action(action_type, phrase) -> {"type":..., "phrase":...}
    //   create_payment_parameter(name, value)       -> {"name":..., "value":...}

    public function testCreatePaymentPromptBasic(): void
    {
        $actions = [['type' => 'Say', 'phrase' => 'Enter card number']];
        $prompt = FunctionResult::createPaymentPrompt('payment-card-number', $actions);

        $this->assertSame('payment-card-number', $prompt['for']);
        $this->assertSame($actions, $prompt['actions']);
        $this->assertArrayNotHasKey('card_type', $prompt);
        $this->assertArrayNotHasKey('error_type', $prompt);
        // Old invented keys are gone.
        $this->assertArrayNotHasKey('text', $prompt);
        $this->assertArrayNotHasKey('language', $prompt);
    }

    public function testCreatePaymentPromptWithCardAndErrorType(): void
    {
        $actions = [['type' => 'Play', 'phrase' => 'https://example.com/p.mp3']];
        $prompt = FunctionResult::createPaymentPrompt('x', $actions, 'visa amex', 'timeout invalid');

        $this->assertSame('visa amex', $prompt['card_type']);
        $this->assertSame('timeout invalid', $prompt['error_type']);
    }

    public function testCreatePaymentActionShape(): void
    {
        $action = FunctionResult::createPaymentAction('Say', 'Enter your number');

        $this->assertSame(['type' => 'Say', 'phrase' => 'Enter your number'], $action);
    }

    public function testCreatePaymentParameterShape(): void
    {
        $param = FunctionResult::createPaymentParameter('card_number', '4111');

        $this->assertSame(['name' => 'card_number', 'value' => '4111'], $param);
    }

    public function testCreatePaymentHelpersComposeIntoPay(): void
    {
        // The helpers feed pay()'s parameters/prompts arrays end-to-end.
        $param = FunctionResult::createPaymentParameter('order', '42');
        $action = FunctionResult::createPaymentAction('Say', 'Card?');
        $prompt = FunctionResult::createPaymentPrompt('payment-card-number', [$action]);

        $fr = (new FunctionResult())->pay(
            'https://pay.example.com/connector',
            'dtmf', null, 'credit-card', 5, 1, true, true, 0, 'reusable',
            null, 'usd', 'en-US', 'woman', null, 'visa mastercard amex',
            [$param], [$prompt]
        );
        $p = $fr->toArray()['action'][0]['SWML']['sections']['main'][1]['pay'];

        $this->assertSame([['name' => 'order', 'value' => '42']], $p['parameters']);
        $this->assertSame(
            [['for' => 'payment-card-number', 'actions' => [['type' => 'Say', 'phrase' => 'Card?']]]],
            $p['prompts']
        );
    }

    // ── Method Chaining ──────────────────────────────────────────────────

    public function testMethodChainingReturnsSelf(): void
    {
        $fr = new FunctionResult();
        $result = $fr
            ->setResponse('chained')
            ->setPostProcess(true)
            ->addAction(['say' => 'a'])
            ->say('b')
            ->hold(60)
            ->stop();

        $this->assertSame($fr, $result);
    }

    public function testMethodChainingAccumulatesActions(): void
    {
        $fr = new FunctionResult();
        $fr->say('first')
           ->say('second')
           ->hangup();

        $actions = $fr->toArray()['action'];
        $this->assertCount(3, $actions);
        $this->assertSame('first', $actions[0]['say']);
        $this->assertSame('second', $actions[1]['say']);
        $this->assertArrayHasKey('hangup', $actions[2]);
    }

    public function testComplexChainProducesCorrectArray(): void
    {
        $fr = new FunctionResult();
        $arr = $fr->setResponse('Done')
            ->setPostProcess(true)
            ->updateGlobalData(['status' => 'complete'])
            ->say('Goodbye')
            ->hangup()
            ->toArray();

        $this->assertSame('Done', $arr['response']);
        $this->assertTrue($arr['post_process']);
        $this->assertCount(3, $arr['action']);
        $this->assertSame(['status' => 'complete'], $arr['action'][0]['set_global_data']);
        $this->assertSame('Goodbye', $arr['action'][1]['say']);
        $this->assertArrayHasKey('hangup', $arr['action'][2]);
    }

    public function testAllActionMethodsReturnSelf(): void
    {
        $fr = new FunctionResult();

        $this->assertSame($fr, $fr->setResponse('x'));
        $this->assertSame($fr, $fr->setPostProcess(false));
        $this->assertSame($fr, $fr->addAction(['a' => 'b']));
        $this->assertSame($fr, $fr->addActions([['c' => 'd']]));
        $this->assertSame($fr, $fr->connect('+1'));
        $this->assertSame($fr, $fr->swmlTransfer('uri', 'response'));
        $this->assertSame($fr, $fr->hangup());
        $this->assertSame($fr, $fr->hold());
        $this->assertSame($fr, $fr->waitForUser());
        $this->assertSame($fr, $fr->stop());
        $this->assertSame($fr, $fr->updateGlobalData([]));
        $this->assertSame($fr, $fr->removeGlobalData([]));
        $this->assertSame($fr, $fr->setMetadata([]));
        $this->assertSame($fr, $fr->removeMetadata([]));
        $this->assertSame($fr, $fr->swmlUserEvent([]));
        $this->assertSame($fr, $fr->swmlChangeStep('s'));
        $this->assertSame($fr, $fr->swmlChangeContext('c'));
        $this->assertSame($fr, $fr->switchContext('p'));
        $this->assertSame($fr, $fr->replaceInHistory('r'));
        $this->assertSame($fr, $fr->say('s'));
        $this->assertSame($fr, $fr->playBackgroundFile('f'));
        $this->assertSame($fr, $fr->stopBackgroundFile());
        $this->assertSame($fr, $fr->recordCall());
        $this->assertSame($fr, $fr->stopRecordCall());
        $this->assertSame($fr, $fr->addDynamicHints([]));
        $this->assertSame($fr, $fr->clearDynamicHints());
        $this->assertSame($fr, $fr->setEndOfSpeechTimeout(100));
        $this->assertSame($fr, $fr->setSpeechEventTimeout(100));
        $this->assertSame($fr, $fr->toggleFunctions([]));
        $this->assertSame($fr, $fr->enableFunctionsOnTimeout());
        $this->assertSame($fr, $fr->enableExtensiveData());
        $this->assertSame($fr, $fr->updateSettings([]));
        $this->assertSame($fr, $fr->executeSwml([]));
        $this->assertSame($fr, $fr->joinConference('c'));
        $this->assertSame($fr, $fr->joinRoom('r'));
        $this->assertSame($fr, $fr->sipRefer('sip:x'));
        $this->assertSame($fr, $fr->tap('uri'));
        $this->assertSame($fr, $fr->stopTap());
        $this->assertSame($fr, $fr->sendSms('a', 'b', 'c'));
        $this->assertSame($fr, $fr->pay('url'));
        $this->assertSame($fr, $fr->executeRpc('m'));
        $this->assertSame($fr, $fr->rpcDial('+1', '+2', 'https://swml'));
        $this->assertSame($fr, $fr->rpcAiMessage('id', 'msg'));
        $this->assertSame($fr, $fr->rpcAiUnhold('id'));
        $this->assertSame($fr, $fr->simulateUserInput('txt'));
    }

    /**
     * recordCall() accepts the typed RecordDirection enum and a bare string
     * interchangeably for $direction, producing the identical emitted action.
     * Uses RecordDirection::Listen — the value that distinguishes record_call's
     * set {speak,listen,both} from tap's {speak,hear,both}; the Python reference
     * validates these as two separate lists. No mocks — assertions read the real
     * built SWML action payload.
     */
    public function testRecordCallAcceptsRecordDirectionEnumOrString(): void
    {
        // The backed enum's value is the canonical wire direction string, and
        // record_call uses 'listen' (not tap's 'hear'). recordCall now routes
        // through the SWML document, so read the verb out of it.
        $this->assertSame('listen', RecordDirection::Listen->value);

        $enum = new FunctionResult();
        $enum->recordCall('rec-1', true, 'mp3', RecordDirection::Listen);
        $string = new FunctionResult();
        $string->recordCall('rec-1', true, 'mp3', 'listen');

        $enumRec = $enum->toArray()['action'][0]['SWML']['sections']['main'][0]['record_call'];
        $stringRec = $string->toArray()['action'][0]['SWML']['sections']['main'][0]['record_call'];

        $this->assertSame(
            $stringRec,
            $enumRec,
            'recordCall enum and string $direction must emit the identical record_call',
        );
        // And the wire value really is the normalized string.
        $this->assertSame('listen', $enumRec['direction']);
    }

    public function testTapAcceptsTapDirectionEnumOrString(): void
    {
        $enum = new FunctionResult();
        $enum->tap('wss://tap.example.com', 'tap-1', TapDirection::Hear, 'PCMA');
        $string = new FunctionResult();
        $string->tap('wss://tap.example.com', 'tap-1', 'hear', 'PCMA');

        $enumTap = $enum->toArray()['action'][0]['SWML']['sections']['main'][0]['tap'];
        $stringTap = $string->toArray()['action'][0]['SWML']['sections']['main'][0]['tap'];

        $this->assertSame(
            $stringTap,
            $enumTap,
            'tap enum and string $direction must emit the identical tap action',
        );
        $this->assertSame('hear', $enumTap['direction']);
    }
}
