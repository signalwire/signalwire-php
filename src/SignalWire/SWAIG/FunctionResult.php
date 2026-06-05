<?php

declare(strict_types=1);

namespace SignalWire\SWAIG;

class FunctionResult
{
    private string $response;
    private bool $postProcess;
    private array $actions = [];

    public function __construct(?string $response = '', bool $postProcess = false)
    {
        $this->response = $response ?? '';
        $this->postProcess = $postProcess;
    }

    // ── Core ─────────────────────────────────────────────────────────────

    public function setResponse(string $text): self
    {
        $this->response = $text;
        return $this;
    }

    public function setPostProcess(bool $val): self
    {
        $this->postProcess = $val;
        return $this;
    }

    public function addAction(array $action): self
    {
        $this->actions[] = $action;
        return $this;
    }

    public function addActions(array $actions): self
    {
        foreach ($actions as $action) {
            $this->actions[] = $action;
        }
        return $this;
    }

    public function toArray(): array
    {
        $result = ['response' => $this->response];

        if (!empty($this->actions)) {
            $result['action'] = $this->actions;
        }

        if ($this->postProcess) {
            $result['post_process'] = true;
        }

        return $result;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    // ── Call Control ─────────────────────────────────────────────────────

    public function connect(string $destination, bool $final = false, string $from = ''): self
    {
        $connectObj = ['to' => $destination];
        if ($from !== '') {
            $connectObj['from'] = $from;
        }

        $this->actions[] = [
            'SWML' => [
                'sections' => [
                    'main' => [
                        ['connect' => $connectObj],
                    ],
                ],
            ],
        ];

        return $this;
    }

    public function swmlTransfer(string $dest, string $aiResponse = '', bool $final = false): self
    {
        $this->actions[] = ['transfer_uri' => $dest];

        if ($aiResponse !== '') {
            $this->response = $aiResponse;
        }

        return $this;
    }

    public function hangup(): self
    {
        $this->actions[] = ['hangup' => new \stdClass()];
        return $this;
    }

    public function hold(int $timeout = 300): self
    {
        $clamped = max(0, min(900, $timeout));
        $this->actions[] = ['hold' => ['timeout' => $clamped]];
        return $this;
    }

    public function waitForUser(?bool $enabled = null, ?int $timeout = null, ?bool $answerFirst = null): self
    {
        if ($enabled === null && $timeout === null && $answerFirst === null) {
            $this->actions[] = ['wait_for_user' => true];
            return $this;
        }

        $params = [];
        if ($enabled !== null) {
            $params['enabled'] = $enabled;
        }
        if ($timeout !== null) {
            $params['timeout'] = $timeout;
        }
        if ($answerFirst !== null) {
            $params['answer_first'] = $answerFirst;
        }

        $this->actions[] = ['wait_for_user' => $params];
        return $this;
    }

    public function stop(): self
    {
        $this->actions[] = ['stop' => true];
        return $this;
    }

    // ── State & Data ─────────────────────────────────────────────────────

    public function updateGlobalData(array $data): self
    {
        $this->actions[] = ['set_global_data' => $data];
        return $this;
    }

    public function removeGlobalData(array $keys): self
    {
        $this->actions[] = ['remove_global_data' => ['keys' => $keys]];
        return $this;
    }

    public function setMetadata(array $data): self
    {
        $this->actions[] = ['set_meta_data' => $data];
        return $this;
    }

    public function removeMetadata(array $keys): self
    {
        $this->actions[] = ['remove_meta_data' => ['keys' => $keys]];
        return $this;
    }

    public function swmlUserEvent(array $eventData): self
    {
        $this->actions[] = ['user_event' => $eventData];
        return $this;
    }

    public function swmlChangeStep(string $stepName): self
    {
        $this->actions[] = ['context_switch' => ['step' => $stepName]];
        return $this;
    }

    public function swmlChangeContext(string $contextName): self
    {
        $this->actions[] = ['context_switch' => ['context' => $contextName]];
        return $this;
    }

    public function switchContext(
        string $systemPrompt,
        string $userPrompt = '',
        bool $consolidate = false,
        bool $fullReset = false,
        bool $isolated = false
    ): self {
        $ctx = ['system_prompt' => $systemPrompt];

        if ($userPrompt !== '') {
            $ctx['user_prompt'] = $userPrompt;
        }
        if ($consolidate) {
            $ctx['consolidate'] = true;
        }
        if ($fullReset) {
            $ctx['full_reset'] = true;
        }
        if ($isolated) {
            $ctx['isolated'] = true;
        }

        $this->actions[] = ['context_switch' => $ctx];
        return $this;
    }

    /**
     * @param string|bool $text
     */
    public function replaceInHistory($text): self
    {
        if ($text === true) {
            $this->actions[] = ['replace_history' => 'summary'];
        } else {
            $this->actions[] = ['replace_history' => (string) $text];
        }
        return $this;
    }

    // ── Media ────────────────────────────────────────────────────────────

    public function say(string $text): self
    {
        $this->actions[] = ['say' => $text];
        return $this;
    }

    public function playBackgroundFile(string $filename, bool $wait = false): self
    {
        if ($wait) {
            $this->actions[] = ['play_background_file_wait' => $filename];
        } else {
            $this->actions[] = ['play_background_file' => $filename];
        }
        return $this;
    }

    public function stopBackgroundFile(): self
    {
        $this->actions[] = ['stop_background_file' => true];
        return $this;
    }

    /**
     * @param RecordDirection|string $direction stream direction — the typed
     *   {@see RecordDirection} enum (typo-checked at the call site) or a bare
     *   string (parity with Python's `record_call`). Normalized to the wire
     *   string ('speak'/'listen'/'both'). Note `record_call` uses 'listen'
     *   where {@see FunctionResult::tap()} uses 'hear' — distinct closed sets.
     */
    public function recordCall(
        string $controlId = '',
        bool $stereo = false,
        string $format = 'wav',
        RecordDirection|string $direction = 'both'
    ): self {
        $record = [
            'stereo' => $stereo,
            'format' => $format,
            'direction' => $direction instanceof RecordDirection ? $direction->value : $direction,
            'initiator' => 'system',
        ];

        if ($controlId !== '') {
            $record['control_id'] = $controlId;
        }

        $this->actions[] = ['record_call' => $record];
        return $this;
    }

    public function stopRecordCall(string $controlId = ''): self
    {
        if ($controlId !== '') {
            $this->actions[] = ['stop_record_call' => ['control_id' => $controlId]];
        } else {
            $this->actions[] = ['stop_record_call' => new \stdClass()];
        }
        return $this;
    }

    // ── Speech & AI ──────────────────────────────────────────────────────

    public function addDynamicHints(array $hints): self
    {
        $this->actions[] = ['add_dynamic_hints' => $hints];
        return $this;
    }

    public function clearDynamicHints(): self
    {
        $this->actions[] = ['clear_dynamic_hints' => true];
        return $this;
    }

    public function setEndOfSpeechTimeout(int $ms): self
    {
        $this->actions[] = ['end_of_speech_timeout' => $ms];
        return $this;
    }

    public function setSpeechEventTimeout(int $ms): self
    {
        $this->actions[] = ['speech_event_timeout' => $ms];
        return $this;
    }

    public function toggleFunctions(array $toggles): self
    {
        $formatted = [];
        foreach ($toggles as $name => $active) {
            $formatted[] = ['function' => $name, 'active' => $active];
        }
        $this->actions[] = ['toggle_functions' => $formatted];
        return $this;
    }

    public function enableFunctionsOnTimeout(bool $enabled = true): self
    {
        $this->actions[] = ['functions_on_timeout' => $enabled];
        return $this;
    }

    public function enableExtensiveData(bool $enabled = true): self
    {
        $this->actions[] = ['extensive_data' => $enabled];
        return $this;
    }

    public function updateSettings(array $settings): self
    {
        $this->actions[] = ['ai_settings' => $settings];
        return $this;
    }

    // ── Advanced ─────────────────────────────────────────────────────────

    /**
     * @param array|string $swmlContent
     */
    public function executeSwml($swmlContent, bool $transfer = false): self
    {
        if (is_string($swmlContent)) {
            $swmlContent = json_decode($swmlContent, true);
        }

        if ($transfer) {
            $this->actions[] = ['transfer_swml' => $swmlContent];
        } else {
            $this->actions[] = ['SWML' => $swmlContent];
        }

        return $this;
    }

    /**
     * Join an ad-hoc audio conference with RELAY and CXML calls using SWML.
     *
     * Full parity with signalwire-python core/function_result.py
     * `join_conference`: 18 optional parameters, 7 validations, and the
     * same simple/full emission. When every parameter is at its default the
     * payload collapses to the bare conference-NAME string
     * ({"join_conference": "<name>"}); otherwise it is the object form keyed
     * by snake_case wire keys, with each key emitted only when it differs
     * from its default. The {"join_conference": ...} payload is wrapped in a
     * full SWML document and emitted through {@see FunctionResult::executeSwml()}
     * — the same path Python's `record_call` uses — so it lands under the
     * "SWML" action key.
     *
     * Note: Python uses `wait_url` (SWML URL) for hold music; there is no
     * `hold_audio` parameter. (The previously-invented `holdAudio`/`hold_audio`
     * had no Python equivalent and was removed.)
     *
     * @param mixed $result Switch on return_value when object {} or cond when
     *                      array []; null to omit (parity with Python `Optional[Any]`).
     * @throws \InvalidArgumentException on any of the 7 validation failures.
     */
    public function joinConference(
        string $name,
        bool $muted = false,
        string $beep = 'true',
        bool $startOnEnter = true,
        bool $endOnExit = false,
        ?string $waitUrl = null,
        int $maxParticipants = 250,
        string $record = 'do-not-record',
        ?string $region = null,
        string $trim = 'trim-silence',
        ?string $coach = null,
        ?string $statusCallbackEvent = null,
        ?string $statusCallback = null,
        string $statusCallbackMethod = 'POST',
        ?string $recordingStatusCallback = null,
        string $recordingStatusCallbackMethod = 'POST',
        string $recordingStatusCallbackEvent = 'completed',
        mixed $result = null
    ): self {
        // Validate beep parameter.
        $validBeepValues = ['true', 'false', 'onEnter', 'onExit'];
        if (!in_array($beep, $validBeepValues, true)) {
            throw new \InvalidArgumentException(
                'beep must be one of ' . self::pythonList($validBeepValues)
            );
        }

        // Validate max_participants.
        if ($maxParticipants <= 0 || $maxParticipants > 250) {
            throw new \InvalidArgumentException(
                'max_participants must be a positive integer <= 250'
            );
        }

        // Validate record parameter.
        $validRecordValues = ['do-not-record', 'record-from-start'];
        if (!in_array($record, $validRecordValues, true)) {
            throw new \InvalidArgumentException(
                'record must be one of ' . self::pythonList($validRecordValues)
            );
        }

        // Validate trim parameter.
        $validTrimValues = ['trim-silence', 'do-not-trim'];
        if (!in_array($trim, $validTrimValues, true)) {
            throw new \InvalidArgumentException(
                'trim must be one of ' . self::pythonList($validTrimValues)
            );
        }

        // Validate status_callback_method / recording_status_callback_method.
        $validMethods = ['GET', 'POST'];
        if (!in_array($statusCallbackMethod, $validMethods, true)) {
            throw new \InvalidArgumentException(
                'status_callback_method must be one of ' . self::pythonList($validMethods)
            );
        }
        if (!in_array($recordingStatusCallbackMethod, $validMethods, true)) {
            throw new \InvalidArgumentException(
                'recording_status_callback_method must be one of ' . self::pythonList($validMethods)
            );
        }

        // Validate name (after the closed-set checks, mirroring Python order).
        if (trim($name) === '') {
            throw new \InvalidArgumentException('name cannot be empty');
        }

        // Simple form — when everything is at its default, the payload is just
        // the conference name string.
        if (
            !$muted && $beep === 'true' && $startOnEnter && !$endOnExit &&
            $waitUrl === null && $maxParticipants === 250 && $record === 'do-not-record' &&
            $region === null && $trim === 'trim-silence' && $coach === null &&
            $statusCallbackEvent === null && $statusCallback === null &&
            $statusCallbackMethod === 'POST' && $recordingStatusCallback === null &&
            $recordingStatusCallbackMethod === 'POST' &&
            $recordingStatusCallbackEvent === 'completed' && $result === null
        ) {
            $joinParams = $name;
        } else {
            // Full object form — emit each non-default param under its
            // snake_case wire key.
            $joinParams = ['name' => $name];
            if ($muted) {
                $joinParams['muted'] = $muted;
            }
            if ($beep !== 'true') {
                $joinParams['beep'] = $beep;
            }
            if (!$startOnEnter) {
                $joinParams['start_on_enter'] = $startOnEnter;
            }
            if ($endOnExit) {
                $joinParams['end_on_exit'] = $endOnExit;
            }
            if ($waitUrl !== null && $waitUrl !== '') {
                $joinParams['wait_url'] = $waitUrl;
            }
            if ($maxParticipants !== 250) {
                $joinParams['max_participants'] = $maxParticipants;
            }
            if ($record !== 'do-not-record') {
                $joinParams['record'] = $record;
            }
            if ($region !== null && $region !== '') {
                $joinParams['region'] = $region;
            }
            if ($trim !== 'trim-silence') {
                $joinParams['trim'] = $trim;
            }
            if ($coach !== null && $coach !== '') {
                $joinParams['coach'] = $coach;
            }
            if ($statusCallbackEvent !== null && $statusCallbackEvent !== '') {
                $joinParams['status_callback_event'] = $statusCallbackEvent;
            }
            if ($statusCallback !== null && $statusCallback !== '') {
                $joinParams['status_callback'] = $statusCallback;
            }
            if ($statusCallbackMethod !== 'POST') {
                $joinParams['status_callback_method'] = $statusCallbackMethod;
            }
            if ($recordingStatusCallback !== null && $recordingStatusCallback !== '') {
                $joinParams['recording_status_callback'] = $recordingStatusCallback;
            }
            if ($recordingStatusCallbackMethod !== 'POST') {
                $joinParams['recording_status_callback_method'] = $recordingStatusCallbackMethod;
            }
            if ($recordingStatusCallbackEvent !== 'completed') {
                $joinParams['recording_status_callback_event'] = $recordingStatusCallbackEvent;
            }
            if ($result !== null) {
                $joinParams['result'] = $result;
            }
        }

        // Wrap in a full SWML document and emit via executeSwml (same path
        // Python's record_call uses → lands under the "SWML" action key).
        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['join_conference' => $joinParams],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    /**
     * Render a list of strings the way Python's `repr(list)` does
     * (e.g. ['true', 'false', 'onEnter', 'onExit']) so the validation
     * messages match the reference byte-for-byte.
     *
     * @param list<string> $values
     */
    private static function pythonList(array $values): string
    {
        return '[' . implode(', ', array_map(
            static fn (string $v): string => "'" . $v . "'",
            $values
        )) . ']';
    }

    public function joinRoom(string $name): self
    {
        $this->actions[] = ['join_room' => ['name' => $name]];
        return $this;
    }

    public function sipRefer(string $toUri): self
    {
        $this->actions[] = ['sip_refer' => ['to_uri' => $toUri]];
        return $this;
    }

    /**
     * @param TapDirection|string $direction stream direction — the typed
     *   {@see TapDirection} enum (typo-checked at the call site) or a bare
     *   string (parity with Python's `tap`). Normalized to the wire string
     *   ('speak'/'hear'/'both').
     */
    public function tap(
        string $uri,
        string $controlId = '',
        TapDirection|string $direction = 'both',
        string $codec = 'PCMU'
    ): self {
        $tapObj = [
            'uri' => $uri,
            'direction' => $direction instanceof TapDirection ? $direction->value : $direction,
            'codec' => $codec,
        ];

        if ($controlId !== '') {
            $tapObj['control_id'] = $controlId;
        }

        $this->actions[] = ['tap' => $tapObj];
        return $this;
    }

    public function stopTap(string $controlId = ''): self
    {
        if ($controlId !== '') {
            $this->actions[] = ['stop_tap' => ['control_id' => $controlId]];
        } else {
            $this->actions[] = ['stop_tap' => new \stdClass()];
        }
        return $this;
    }

    public function sendSms(
        string $to,
        string $from,
        string $body,
        array $media = [],
        array $tags = []
    ): self {
        $sms = [
            'to_number' => $to,
            'from_number' => $from,
            'body' => $body,
        ];

        if (!empty($media)) {
            $sms['media'] = $media;
        }
        if (!empty($tags)) {
            $sms['tags'] = $tags;
        }

        $this->actions[] = ['send_sms' => $sms];
        return $this;
    }

    public function pay(
        string $connectorUrl,
        string $inputMethod = 'dtmf',
        string $actionUrl = '',
        int $timeout = 600,
        int $maxAttempts = 3
    ): self {
        $payObj = [
            'payment_connector_url' => $connectorUrl,
            'input_method' => $inputMethod,
            'timeout' => $timeout,
            'max_attempts' => $maxAttempts,
        ];

        if ($actionUrl !== '') {
            $payObj['action_url'] = $actionUrl;
        }

        $this->actions[] = ['pay' => $payObj];
        return $this;
    }

    // ── RPC ──────────────────────────────────────────────────────────────

    public function executeRpc(string $method, array $params = []): self
    {
        $rpc = [
            'method' => $method,
            'jsonrpc' => '2.0',
        ];

        if (!empty($params)) {
            $rpc['params'] = $params;
        }

        $this->actions[] = ['execute_rpc' => $rpc];
        return $this;
    }

    public function rpcDial(
        string $to,
        string $from = '',
        ?string $destSwml = null,
        ?int $callTimeout = null,
        string $region = ''
    ): self {
        $params = ['to_number' => $to];

        if ($from !== '') {
            $params['from_number'] = $from;
        }
        if ($destSwml !== null) {
            $params['dest_swml'] = $destSwml;
        }
        if ($callTimeout !== null) {
            $params['call_timeout'] = $callTimeout;
        }
        if ($region !== '') {
            $params['region'] = $region;
        }

        return $this->executeRpc('calling.dial', $params);
    }

    public function rpcAiMessage(string $callId, string $messageText): self
    {
        return $this->executeRpc('calling.ai_message', [
            'call_id' => $callId,
            'message_text' => $messageText,
        ]);
    }

    public function rpcAiUnhold(string $callId): self
    {
        return $this->executeRpc('calling.ai_unhold', [
            'call_id' => $callId,
        ]);
    }

    public function simulateUserInput(string $text): self
    {
        $this->actions[] = ['simulate_user_input' => $text];
        return $this;
    }

    // ── Payment Helpers (static) ─────────────────────────────────────────

    public static function createPaymentPrompt(
        string $text,
        string $language = 'en-US',
        string $voice = ''
    ): array {
        $prompt = [
            'text' => $text,
            'language' => $language,
        ];

        if ($voice !== '') {
            $prompt['voice'] = $voice;
        }

        return $prompt;
    }

    public static function createPaymentAction(
        string $type,
        string $text,
        string $language = 'en-US',
        string $voice = ''
    ): array {
        $action = [
            'type' => $type,
            'text' => $text,
            'language' => $language,
        ];

        if ($voice !== '') {
            $action['voice'] = $voice;
        }

        return $action;
    }

    public static function createPaymentParameter(
        string $name,
        string $type,
        array $config = []
    ): array {
        return array_merge(['name' => $name, 'type' => $type], $config);
    }
}
