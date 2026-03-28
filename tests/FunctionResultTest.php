<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWAIG\FunctionResult;

class FunctionResultTest extends TestCase
{
    // ── Construction ──────────────────────────────────────────────────────

    public function testDefaultConstructionHasEmptyResponseAndNoPostProcess(): void
    {
        $fr = new FunctionResult();
        $arr = $fr->toArray();

        $this->assertSame('', $arr['response']);
        $this->assertArrayNotHasKey('action', $arr);
        $this->assertArrayNotHasKey('post_process', $arr);
    }

    public function testConstructionWithResponseAndPostProcess(): void
    {
        $fr = new FunctionResult('hello', true);
        $arr = $fr->toArray();

        $this->assertSame('hello', $arr['response']);
        $this->assertTrue($arr['post_process']);
    }

    public function testConstructionWithNullResponseDefaultsToEmptyString(): void
    {
        $fr = new FunctionResult(null);
        $this->assertSame('', $fr->toArray()['response']);
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

    public function testToArrayIncludesResponseAlways(): void
    {
        $fr = new FunctionResult();
        $this->assertArrayHasKey('response', $fr->toArray());
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
        $fr = new FunctionResult('', true);
        $this->assertArrayHasKey('post_process', $fr->toArray());
        $this->assertTrue($fr->toArray()['post_process']);
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
    }

    public function testSwmlTransferBasic(): void
    {
        $fr = new FunctionResult();
        $fr->swmlTransfer('https://example.com/swml');
        $action = $fr->toArray()['action'][0];

        $this->assertSame('https://example.com/swml', $action['transfer_uri']);
    }

    public function testSwmlTransferWithAiResponse(): void
    {
        $fr = new FunctionResult();
        $fr->swmlTransfer('https://example.com/swml', 'Transferring now');
        $arr = $fr->toArray();

        $this->assertSame('Transferring now', $arr['response']);
        $this->assertSame('https://example.com/swml', $arr['action'][0]['transfer_uri']);
    }

    public function testSwmlTransferEmptyAiResponseDoesNotOverride(): void
    {
        $fr = new FunctionResult('original');
        $fr->swmlTransfer('https://example.com/swml', '');
        $this->assertSame('original', $fr->toArray()['response']);
    }

    public function testHangup(): void
    {
        $fr = new FunctionResult();
        $fr->hangup();
        $action = $fr->toArray()['action'][0];

        $this->assertArrayHasKey('hangup', $action);
        $json = json_encode($action['hangup']);
        $this->assertSame('{}', $json);
    }

    public function testHoldDefault(): void
    {
        $fr = new FunctionResult();
        $fr->hold();
        $action = $fr->toArray()['action'][0];

        $this->assertSame(300, $action['hold']['timeout']);
    }

    public function testHoldClampsLow(): void
    {
        $fr = new FunctionResult();
        $fr->hold(-50);
        $this->assertSame(0, $fr->toArray()['action'][0]['hold']['timeout']);
    }

    public function testHoldClampsHigh(): void
    {
        $fr = new FunctionResult();
        $fr->hold(9999);
        $this->assertSame(900, $fr->toArray()['action'][0]['hold']['timeout']);
    }

    public function testHoldWithinRange(): void
    {
        $fr = new FunctionResult();
        $fr->hold(450);
        $this->assertSame(450, $fr->toArray()['action'][0]['hold']['timeout']);
    }

    public function testWaitForUserNoParams(): void
    {
        $fr = new FunctionResult();
        $fr->waitForUser();
        $action = $fr->toArray()['action'][0];

        $this->assertTrue($action['wait_for_user']);
    }

    public function testWaitForUserWithParams(): void
    {
        $fr = new FunctionResult();
        $fr->waitForUser(true, 30, false);
        $wfu = $fr->toArray()['action'][0]['wait_for_user'];

        $this->assertIsArray($wfu);
        $this->assertTrue($wfu['enabled']);
        $this->assertSame(30, $wfu['timeout']);
        $this->assertFalse($wfu['answer_first']);
    }

    public function testWaitForUserPartialParams(): void
    {
        $fr = new FunctionResult();
        $fr->waitForUser(null, 60);
        $wfu = $fr->toArray()['action'][0]['wait_for_user'];

        $this->assertIsArray($wfu);
        $this->assertArrayNotHasKey('enabled', $wfu);
        $this->assertSame(60, $wfu['timeout']);
        $this->assertArrayNotHasKey('answer_first', $wfu);
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

    public function testRemoveGlobalData(): void
    {
        $fr = new FunctionResult();
        $fr->removeGlobalData(['k1', 'k2']);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['keys' => ['k1', 'k2']], $action['remove_global_data']);
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
        $fr = new FunctionResult();
        $fr->removeMetadata(['x', 'y']);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['keys' => ['x', 'y']], $action['remove_meta_data']);
    }

    public function testSwmlUserEvent(): void
    {
        $fr = new FunctionResult();
        $fr->swmlUserEvent(['type' => 'custom', 'data' => 123]);
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['type' => 'custom', 'data' => 123], $action['user_event']);
    }

    public function testSwmlChangeStep(): void
    {
        $fr = new FunctionResult();
        $fr->swmlChangeStep('greeting');
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['step' => 'greeting'], $action['context_switch']);
    }

    public function testSwmlChangeContext(): void
    {
        $fr = new FunctionResult();
        $fr->swmlChangeContext('billing');
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['context' => 'billing'], $action['context_switch']);
    }

    public function testSwitchContextSimple(): void
    {
        $fr = new FunctionResult();
        $fr->switchContext('You are a helpful agent.');
        $cs = $fr->toArray()['action'][0]['context_switch'];

        $this->assertSame('You are a helpful agent.', $cs['system_prompt']);
        $this->assertArrayNotHasKey('user_prompt', $cs);
        $this->assertArrayNotHasKey('consolidate', $cs);
        $this->assertArrayNotHasKey('full_reset', $cs);
        $this->assertArrayNotHasKey('isolated', $cs);
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

    public function testReplaceInHistoryWithString(): void
    {
        $fr = new FunctionResult();
        $fr->replaceInHistory('redacted');
        $this->assertSame('redacted', $fr->toArray()['action'][0]['replace_history']);
    }

    public function testReplaceInHistoryWithTrue(): void
    {
        $fr = new FunctionResult();
        $fr->replaceInHistory(true);
        $this->assertSame('summary', $fr->toArray()['action'][0]['replace_history']);
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
        $fr = new FunctionResult();
        $fr->playBackgroundFile('music.mp3');
        $action = $fr->toArray()['action'][0];

        $this->assertSame('music.mp3', $action['play_background_file']);
        $this->assertArrayNotHasKey('play_background_file_wait', $action);
    }

    public function testPlayBackgroundFileWithWait(): void
    {
        $fr = new FunctionResult();
        $fr->playBackgroundFile('music.mp3', true);
        $action = $fr->toArray()['action'][0];

        $this->assertSame('music.mp3', $action['play_background_file_wait']);
        $this->assertArrayNotHasKey('play_background_file', $action);
    }

    public function testStopBackgroundFile(): void
    {
        $fr = new FunctionResult();
        $fr->stopBackgroundFile();
        $this->assertTrue($fr->toArray()['action'][0]['stop_background_file']);
    }

    public function testRecordCallDefaults(): void
    {
        $fr = new FunctionResult();
        $fr->recordCall();
        $rec = $fr->toArray()['action'][0]['record_call'];

        $this->assertFalse($rec['stereo']);
        $this->assertSame('wav', $rec['format']);
        $this->assertSame('both', $rec['direction']);
        $this->assertSame('system', $rec['initiator']);
        $this->assertArrayNotHasKey('control_id', $rec);
    }

    public function testRecordCallWithControlId(): void
    {
        $fr = new FunctionResult();
        $fr->recordCall('rec-1', true, 'mp3', 'speak');
        $rec = $fr->toArray()['action'][0]['record_call'];

        $this->assertSame('rec-1', $rec['control_id']);
        $this->assertTrue($rec['stereo']);
        $this->assertSame('mp3', $rec['format']);
        $this->assertSame('speak', $rec['direction']);
    }

    public function testStopRecordCallWithoutControlId(): void
    {
        $fr = new FunctionResult();
        $fr->stopRecordCall();
        $action = $fr->toArray()['action'][0];

        $json = json_encode($action['stop_record_call']);
        $this->assertSame('{}', $json);
    }

    public function testStopRecordCallWithControlId(): void
    {
        $fr = new FunctionResult();
        $fr->stopRecordCall('rec-1');
        $action = $fr->toArray()['action'][0];

        $this->assertSame(['control_id' => 'rec-1'], $action['stop_record_call']);
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
        $fr = new FunctionResult();
        $fr->clearDynamicHints();
        $this->assertTrue($fr->toArray()['action'][0]['clear_dynamic_hints']);
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
        $fr = new FunctionResult();
        $fr->enableFunctionsOnTimeout();
        $this->assertTrue($fr->toArray()['action'][0]['functions_on_timeout']);
    }

    public function testEnableFunctionsOnTimeoutFalse(): void
    {
        $fr = new FunctionResult();
        $fr->enableFunctionsOnTimeout(false);
        $this->assertFalse($fr->toArray()['action'][0]['functions_on_timeout']);
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
        $fr = new FunctionResult();
        $fr->updateSettings(['temperature' => 0.7, 'top_p' => 0.9]);
        $this->assertSame(
            ['temperature' => 0.7, 'top_p' => 0.9],
            $fr->toArray()['action'][0]['ai_settings']
        );
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
        $swmlJson = '{"sections":{"main":[{"answer":{}}]}}';
        $fr = new FunctionResult();
        $fr->executeSwml($swmlJson);
        $action = $fr->toArray()['action'][0];

        $expected = ['sections' => ['main' => [['answer' => []]]]];
        $this->assertSame($expected, $action['SWML']);
    }

    public function testExecuteSwmlWithTransfer(): void
    {
        $swml = ['sections' => ['main' => [['answer' => []]]]];
        $fr = new FunctionResult();
        $fr->executeSwml($swml, true);
        $action = $fr->toArray()['action'][0];

        $this->assertSame($swml, $action['transfer_swml']);
        $this->assertArrayNotHasKey('SWML', $action);
    }

    public function testJoinConferenceDefaults(): void
    {
        $fr = new FunctionResult();
        $fr->joinConference('myconf');
        $jc = $fr->toArray()['action'][0]['join_conference'];

        $this->assertSame('myconf', $jc['name']);
        $this->assertFalse($jc['muted']);
        $this->assertSame('true', $jc['beep']);
        $this->assertSame('ring', $jc['hold_audio']);
    }

    public function testJoinConferenceCustom(): void
    {
        $fr = new FunctionResult();
        $fr->joinConference('room1', true, 'false', 'music');
        $jc = $fr->toArray()['action'][0]['join_conference'];

        $this->assertTrue($jc['muted']);
        $this->assertSame('false', $jc['beep']);
        $this->assertSame('music', $jc['hold_audio']);
    }

    public function testJoinRoom(): void
    {
        $fr = new FunctionResult();
        $fr->joinRoom('video-room');
        $this->assertSame(['name' => 'video-room'], $fr->toArray()['action'][0]['join_room']);
    }

    public function testSipRefer(): void
    {
        $fr = new FunctionResult();
        $fr->sipRefer('sip:agent@example.com');
        $this->assertSame(
            ['to_uri' => 'sip:agent@example.com'],
            $fr->toArray()['action'][0]['sip_refer']
        );
    }

    public function testTapBasic(): void
    {
        $fr = new FunctionResult();
        $fr->tap('wss://tap.example.com');
        $t = $fr->toArray()['action'][0]['tap'];

        $this->assertSame('wss://tap.example.com', $t['uri']);
        $this->assertSame('both', $t['direction']);
        $this->assertSame('PCMU', $t['codec']);
        $this->assertArrayNotHasKey('control_id', $t);
    }

    public function testTapWithControlId(): void
    {
        $fr = new FunctionResult();
        $fr->tap('wss://tap.example.com', 'tap-1', 'speak', 'PCMA');
        $t = $fr->toArray()['action'][0]['tap'];

        $this->assertSame('tap-1', $t['control_id']);
        $this->assertSame('speak', $t['direction']);
        $this->assertSame('PCMA', $t['codec']);
    }

    public function testStopTapWithoutControlId(): void
    {
        $fr = new FunctionResult();
        $fr->stopTap();
        $json = json_encode($fr->toArray()['action'][0]['stop_tap']);
        $this->assertSame('{}', $json);
    }

    public function testStopTapWithControlId(): void
    {
        $fr = new FunctionResult();
        $fr->stopTap('tap-1');
        $this->assertSame(
            ['control_id' => 'tap-1'],
            $fr->toArray()['action'][0]['stop_tap']
        );
    }

    public function testSendSmsBasic(): void
    {
        $fr = new FunctionResult();
        $fr->sendSms('+15551111111', '+15552222222', 'Hello');
        $sms = $fr->toArray()['action'][0]['send_sms'];

        $this->assertSame('+15551111111', $sms['to_number']);
        $this->assertSame('+15552222222', $sms['from_number']);
        $this->assertSame('Hello', $sms['body']);
        $this->assertArrayNotHasKey('media', $sms);
        $this->assertArrayNotHasKey('tags', $sms);
    }

    public function testSendSmsWithMediaAndTags(): void
    {
        $fr = new FunctionResult();
        $fr->sendSms(
            '+15551111111',
            '+15552222222',
            'See image',
            ['https://example.com/img.png'],
            ['campaign' => 'promo']
        );
        $sms = $fr->toArray()['action'][0]['send_sms'];

        $this->assertSame(['https://example.com/img.png'], $sms['media']);
        $this->assertSame(['campaign' => 'promo'], $sms['tags']);
    }

    public function testPayBasic(): void
    {
        $fr = new FunctionResult();
        $fr->pay('https://pay.example.com/connector');
        $p = $fr->toArray()['action'][0]['pay'];

        $this->assertSame('https://pay.example.com/connector', $p['payment_connector_url']);
        $this->assertSame('dtmf', $p['input_method']);
        $this->assertSame(600, $p['timeout']);
        $this->assertSame(3, $p['max_attempts']);
        $this->assertArrayNotHasKey('action_url', $p);
    }

    public function testPayWithAllOptions(): void
    {
        $fr = new FunctionResult();
        $fr->pay('https://pay.example.com/connector', 'voice', 'https://pay.example.com/action', 120, 5);
        $p = $fr->toArray()['action'][0]['pay'];

        $this->assertSame('voice', $p['input_method']);
        $this->assertSame('https://pay.example.com/action', $p['action_url']);
        $this->assertSame(120, $p['timeout']);
        $this->assertSame(5, $p['max_attempts']);
    }

    // ── RPC ───────────────────────────────────────────────────────────────

    public function testExecuteRpcWithoutParams(): void
    {
        $fr = new FunctionResult();
        $fr->executeRpc('calling.status');
        $rpc = $fr->toArray()['action'][0]['execute_rpc'];

        $this->assertSame('calling.status', $rpc['method']);
        $this->assertSame('2.0', $rpc['jsonrpc']);
        $this->assertArrayNotHasKey('params', $rpc);
    }

    public function testExecuteRpcWithParams(): void
    {
        $fr = new FunctionResult();
        $fr->executeRpc('calling.dial', ['to_number' => '+15551234567']);
        $rpc = $fr->toArray()['action'][0]['execute_rpc'];

        $this->assertSame('calling.dial', $rpc['method']);
        $this->assertSame(['to_number' => '+15551234567'], $rpc['params']);
    }

    public function testRpcDialMinimal(): void
    {
        $fr = new FunctionResult();
        $fr->rpcDial('+15551234567');
        $rpc = $fr->toArray()['action'][0]['execute_rpc'];

        $this->assertSame('calling.dial', $rpc['method']);
        $this->assertSame('2.0', $rpc['jsonrpc']);
        $this->assertSame('+15551234567', $rpc['params']['to_number']);
        $this->assertArrayNotHasKey('from_number', $rpc['params']);
        $this->assertArrayNotHasKey('dest_swml', $rpc['params']);
        $this->assertArrayNotHasKey('call_timeout', $rpc['params']);
        $this->assertArrayNotHasKey('region', $rpc['params']);
    }

    public function testRpcDialFull(): void
    {
        $fr = new FunctionResult();
        $fr->rpcDial('+15551234567', '+15559876543', 'https://example.com/swml', 30, 'us-east');
        $rpc = $fr->toArray()['action'][0]['execute_rpc'];

        $this->assertSame('+15559876543', $rpc['params']['from_number']);
        $this->assertSame('https://example.com/swml', $rpc['params']['dest_swml']);
        $this->assertSame(30, $rpc['params']['call_timeout']);
        $this->assertSame('us-east', $rpc['params']['region']);
    }

    public function testRpcAiMessage(): void
    {
        $fr = new FunctionResult();
        $fr->rpcAiMessage('call-abc-123', 'Please hold');
        $rpc = $fr->toArray()['action'][0]['execute_rpc'];

        $this->assertSame('calling.ai_message', $rpc['method']);
        $this->assertSame('call-abc-123', $rpc['params']['call_id']);
        $this->assertSame('Please hold', $rpc['params']['message_text']);
    }

    public function testRpcAiUnhold(): void
    {
        $fr = new FunctionResult();
        $fr->rpcAiUnhold('call-xyz-789');
        $rpc = $fr->toArray()['action'][0]['execute_rpc'];

        $this->assertSame('calling.ai_unhold', $rpc['method']);
        $this->assertSame('call-xyz-789', $rpc['params']['call_id']);
    }

    public function testSimulateUserInput(): void
    {
        $fr = new FunctionResult();
        $fr->simulateUserInput('I need help');
        $this->assertSame('I need help', $fr->toArray()['action'][0]['simulate_user_input']);
    }

    // ── Payment Helpers (static) ──────────────────────────────────────────

    public function testCreatePaymentPromptDefaults(): void
    {
        $prompt = FunctionResult::createPaymentPrompt('Enter card number');

        $this->assertSame('Enter card number', $prompt['text']);
        $this->assertSame('en-US', $prompt['language']);
        $this->assertArrayNotHasKey('voice', $prompt);
    }

    public function testCreatePaymentPromptWithVoice(): void
    {
        $prompt = FunctionResult::createPaymentPrompt('Enter card', 'es-MX', 'Polly.Miguel');

        $this->assertSame('es-MX', $prompt['language']);
        $this->assertSame('Polly.Miguel', $prompt['voice']);
    }

    public function testCreatePaymentActionDefaults(): void
    {
        $action = FunctionResult::createPaymentAction('collect', 'Enter your number');

        $this->assertSame('collect', $action['type']);
        $this->assertSame('Enter your number', $action['text']);
        $this->assertSame('en-US', $action['language']);
        $this->assertArrayNotHasKey('voice', $action);
    }

    public function testCreatePaymentActionWithVoice(): void
    {
        $action = FunctionResult::createPaymentAction('confirm', 'Confirm?', 'fr-FR', 'Polly.Lea');

        $this->assertSame('confirm', $action['type']);
        $this->assertSame('fr-FR', $action['language']);
        $this->assertSame('Polly.Lea', $action['voice']);
    }

    public function testCreatePaymentParameterBasic(): void
    {
        $param = FunctionResult::createPaymentParameter('card_number', 'credit_card');

        $this->assertSame('card_number', $param['name']);
        $this->assertSame('credit_card', $param['type']);
    }

    public function testCreatePaymentParameterWithConfig(): void
    {
        $param = FunctionResult::createPaymentParameter('card_number', 'credit_card', [
            'min_length' => 13,
            'max_length' => 19,
        ]);

        $this->assertSame('card_number', $param['name']);
        $this->assertSame('credit_card', $param['type']);
        $this->assertSame(13, $param['min_length']);
        $this->assertSame(19, $param['max_length']);
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
        $this->assertSame($fr, $fr->swmlTransfer('uri'));
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
        $this->assertSame($fr, $fr->rpcDial('+1'));
        $this->assertSame($fr, $fr->rpcAiMessage('id', 'msg'));
        $this->assertSame($fr, $fr->rpcAiUnhold('id'));
        $this->assertSame($fr, $fr->simulateUserInput('txt'));
    }
}
