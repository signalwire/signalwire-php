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
        $result = [];

        // response is omitted when empty (Python parity).
        if ($this->response !== '') {
            $result['response'] = $this->response;
        }

        if (!empty($this->actions)) {
            $result['action'] = $this->actions;
        }

        // post_process only matters when there are actions to execute.
        if ($this->postProcess && !empty($this->actions)) {
            $result['post_process'] = true;
        }

        // Ensure at least one of response or action is present.
        if (empty($result)) {
            $result['response'] = 'Action completed.';
        }

        return $result;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    // ── Call Control ─────────────────────────────────────────────────────

    public function connect(string $destination, bool $final = true, string $from = ''): self
    {
        $connectObj = ['to' => $destination];
        if ($from !== '') {
            $connectObj['from'] = $from;
        }

        // final=true -> permanent transfer; matches the Python reference
        // (function_result.py connect: "transfer": str(final).lower()).
        $this->actions[] = [
            'SWML' => [
                'sections' => [
                    'main' => [
                        ['connect' => $connectObj],
                    ],
                ],
                'version' => '1.0.0',
            ],
            'transfer' => $final ? 'true' : 'false',
        ];

        return $this;
    }

    /**
     * Add a SWML transfer action with AI-response setup for when the transfer
     * completes. Parity with the Python reference `swml_transfer`: builds a SWML
     * document whose `main` runs `{set:{ai_response}}` then `{transfer:{dest}}`,
     * and emits a top-level `transfer` = str($final).lower() that marks the call
     * (non-)final. `$final` defaults TRUE (permanent transfer), same as connect().
     */
    public function swmlTransfer(string $dest, string $aiResponse, bool $final = true): self
    {
        $this->actions[] = [
            'SWML' => [
                'version' => '1.0.0',
                'sections' => [
                    'main' => [
                        ['set' => ['ai_response' => $aiResponse]],
                        ['transfer' => ['dest' => $dest]],
                    ],
                ],
            ],
            'transfer' => $final ? 'true' : 'false',
        ];

        return $this;
    }

    public function hangup(): self
    {
        // Python: add_action("hangup", True) -> {"hangup": true}.
        $this->actions[] = ['hangup' => true];
        return $this;
    }

    public function hold(int $timeout = 300): self
    {
        // Python: add_action("hold", timeout) -> {"hold": <int>} (bare int,
        // not an object). Clamp to [0, 900] first (matches the reference).
        $clamped = max(0, min(900, $timeout));
        $this->actions[] = ['hold' => $clamped];
        return $this;
    }

    public function waitForUser(?bool $enabled = null, ?int $timeout = null, bool $answerFirst = false): self
    {
        // Python emits a SCALAR (not an object): "answer_first" string,
        // a timeout int, an enabled bool, or true as the final fallback —
        // in that precedence order.
        if ($answerFirst) {
            $waitValue = 'answer_first';
        } elseif ($timeout !== null) {
            $waitValue = $timeout;
        } elseif ($enabled !== null) {
            $waitValue = $enabled;
        } else {
            $waitValue = true;
        }

        $this->actions[] = ['wait_for_user' => $waitValue];
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

    /**
     * @param string|list<string> $keys single key or list of keys to remove
     */
    public function removeGlobalData(string|array $keys): self
    {
        // Python: add_action("unset_global_data", keys) -> emits the value
        // (string or list) directly, NOT wrapped in {"keys": ...}.
        $this->actions[] = ['unset_global_data' => $keys];
        return $this;
    }

    public function setMetadata(array $data): self
    {
        $this->actions[] = ['set_meta_data' => $data];
        return $this;
    }

    /**
     * @param string|list<string> $keys single key or list of keys to remove
     */
    public function removeMetadata(string|array $keys): self
    {
        // Python: add_action("unset_meta_data", keys) -> emits the value
        // directly (parity with remove_global_data).
        $this->actions[] = ['unset_meta_data' => $keys];
        return $this;
    }

    public function swmlUserEvent(array $eventData): self
    {
        // Python wraps the event in a SWML document with the data nested under
        // user_event.event. Note the key order here is {sections, version}
        // (sections first) — matching the reference's swml_user_event dict.
        $swmlAction = [
            'sections' => [
                'main' => [
                    ['user_event' => ['event' => $eventData]],
                ],
            ],
            'version' => '1.0.0',
        ];

        $this->actions[] = ['SWML' => $swmlAction];
        return $this;
    }

    public function swmlChangeStep(string $stepName): self
    {
        // Python: add_action("change_step", step_name) -> bare string value.
        $this->actions[] = ['change_step' => $stepName];
        return $this;
    }

    public function swmlChangeContext(string $contextName): self
    {
        // Python: add_action("change_context", context_name) -> bare string.
        $this->actions[] = ['change_context' => $contextName];
        return $this;
    }

    public function switchContext(
        string $systemPrompt,
        string $userPrompt = '',
        bool $consolidate = false,
        bool $fullReset = false,
        bool $isolated = false
    ): self {
        // Python: when ONLY system_prompt is supplied (no user_prompt /
        // consolidate / full_reset — and, for this port's documented `isolated`
        // extension, no isolated), the action value is the BARE system-prompt
        // STRING ({"context_switch": "<prompt>"}), not an object. Parity with
        // the simple/object branch in function_result.py:switch_context and the
        // verified Go/Rust siblings.
        if ($systemPrompt !== '' && $userPrompt === '' && !$consolidate && !$fullReset && !$isolated) {
            $this->actions[] = ['context_switch' => $systemPrompt];
            return $this;
        }

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
     * After first send, replace the tool_call+result pair in history.
     *
     * @param string|bool $text String -> replace the tool_call with an
     *   assistant message containing this text. True (default) -> remove the
     *   pair entirely.
     */
    public function replaceInHistory(string|bool $text = true): self
    {
        // Python: add_action("replace_in_history", text) -> emits the value
        // as-is (the string, or the literal boolean true — not "summary").
        $this->actions[] = ['replace_in_history' => $text];
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
        // Python action name is "playback_bg". With wait, the value is an
        // object {file, wait:true}; without, it is the bare filename string.
        if ($wait) {
            $this->actions[] = ['playback_bg' => ['file' => $filename, 'wait' => true]];
        } else {
            $this->actions[] = ['playback_bg' => $filename];
        }
        return $this;
    }

    public function stopBackgroundFile(): self
    {
        // Python action name is "stop_playback_bg" (value true).
        $this->actions[] = ['stop_playback_bg' => true];
        return $this;
    }

    /**
     * Start background call recording using SWML.
     *
     * Full parity with the Python reference `record_call`: validates the
     * format ({wav,mp3,mp4}) and direction ({speak,listen,both}) closed sets,
     * ALWAYS emits stereo/format/direction/beep/input_sensitivity, adds the
     * optional fields only when supplied, and wraps the {"record_call": ...}
     * verb in a full SWML document emitted via {@see FunctionResult::executeSwml()}
     * (so it lands under the "SWML" action key).
     *
     * @param RecordDirection|string $direction stream direction — the typed
     *   {@see RecordDirection} enum (typo-checked at the call site) or a bare
     *   string (parity with Python's `record_call`). Normalized to the wire
     *   string ('speak'/'listen'/'both'). Note `record_call` uses 'listen'
     *   where {@see FunctionResult::tap()} uses 'hear' — distinct closed sets.
     * @throws \InvalidArgumentException on an invalid format or direction.
     */
    public function recordCall(
        ?string $controlId = null,
        bool $stereo = false,
        string $format = 'wav',
        RecordDirection|string $direction = 'both',
        ?string $terminators = null,
        bool $beep = false,
        float $inputSensitivity = 44.0,
        ?float $initialTimeout = null,
        ?float $endSilenceTimeout = null,
        ?float $maxLength = null,
        ?string $statusUrl = null
    ): self {
        $directionStr = $direction instanceof RecordDirection ? $direction->value : $direction;

        // Validate format (matches the SWML record_call verb schema).
        $validFormats = ['wav', 'mp3', 'mp4'];
        if (!in_array($format, $validFormats, true)) {
            throw new \InvalidArgumentException("format must be 'wav', 'mp3', or 'mp4'");
        }

        // Validate direction.
        $validDirections = ['speak', 'listen', 'both'];
        if (!in_array($directionStr, $validDirections, true)) {
            throw new \InvalidArgumentException("direction must be 'speak', 'listen', or 'both'");
        }

        // Always-emitted fields (parity: stereo/format/direction/beep/
        // input_sensitivity are present even at their defaults).
        $record = [
            'stereo' => $stereo,
            'format' => $format,
            'direction' => $directionStr,
            'beep' => $beep,
            'input_sensitivity' => $inputSensitivity,
        ];

        if ($controlId !== null && $controlId !== '') {
            $record['control_id'] = $controlId;
        }
        if ($terminators !== null && $terminators !== '') {
            $record['terminators'] = $terminators;
        }
        if ($initialTimeout !== null) {
            $record['initial_timeout'] = $initialTimeout;
        }
        if ($endSilenceTimeout !== null) {
            $record['end_silence_timeout'] = $endSilenceTimeout;
        }
        if ($maxLength !== null) {
            $record['max_length'] = $maxLength;
        }
        if ($statusUrl !== null && $statusUrl !== '') {
            $record['status_url'] = $statusUrl;
        }

        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['record_call' => $record],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    public function stopRecordCall(?string $controlId = null): self
    {
        // Python wraps {"stop_record_call": {...}} in a SWML document. Empty
        // object ({}) when no control_id is supplied.
        $stopParams = ($controlId !== null && $controlId !== '')
            ? ['control_id' => $controlId]
            : new \stdClass();

        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['stop_record_call' => $stopParams],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    // ── Speech & AI ──────────────────────────────────────────────────────

    public function addDynamicHints(array $hints): self
    {
        $this->actions[] = ['add_dynamic_hints' => $hints];
        return $this;
    }

    public function clearDynamicHints(): self
    {
        // Python: self.action.append({"clear_dynamic_hints": {}}) — the value is
        // an empty OBJECT, not a boolean. In PHP an empty array [] json_encodes
        // to [] (a JSON array), so we MUST use new \stdClass() to emit {} (parity
        // with every other port: Go map[string]any{}, Rust json!({})).
        $this->actions[] = ['clear_dynamic_hints' => new \stdClass()];
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
        // Python action name is "functions_on_speaker_timeout".
        $this->actions[] = ['functions_on_speaker_timeout' => $enabled];
        return $this;
    }

    public function enableExtensiveData(bool $enabled = true): self
    {
        $this->actions[] = ['extensive_data' => $enabled];
        return $this;
    }

    public function updateSettings(array $settings): self
    {
        // Python action name is "settings" (not "ai_settings").
        $this->actions[] = ['settings' => $settings];
        return $this;
    }

    // ── Advanced ─────────────────────────────────────────────────────────

    /**
     * Execute SWML content with optional transfer behavior. Parity with the
     * Python reference `execute_swml`: a string is parsed to an array (on parse
     * failure it is wrapped as {"raw_swml": <text>}); when $transfer is true the
     * key "transfer" => "true" is set INSIDE the SWML document; the action is
     * ALWAYS added under the "SWML" key (there is no separate transfer key).
     *
     * @param array|string $swmlContent SWML JSON string or already-decoded array.
     */
    public function executeSwml($swmlContent, bool $transfer = false): self
    {
        if (is_string($swmlContent)) {
            // Decode to OBJECTS, not associative arrays: json_decode($s, true)
            // collapses every nested empty object {} into an empty array [],
            // which would then re-encode as [] on the wire (a value-TYPE
            // divergence from Python's json.loads, which keeps {} a dict — and
            // from the Go/Rust siblings, which preserve {} too). Object-mode
            // decode keeps {} a stdClass and JSON arrays as PHP arrays.
            $decoded = json_decode($swmlContent);
            if ($decoded instanceof \stdClass) {
                if ($transfer) {
                    $decoded->transfer = 'true';
                }
                $this->actions[] = ['SWML' => $decoded];
                return $this;
            }
            // On invalid JSON (or a non-object top level), mirror Python's
            // {"raw_swml": <text>} fallback. (Python only spreads when the
            // top-level parse yields a dict; everything else is raw_swml.)
            $swmlContent = is_array($decoded)
                ? $decoded
                : ['raw_swml' => $swmlContent];
        }

        if ($transfer) {
            $swmlContent['transfer'] = 'true';
        }

        $this->actions[] = ['SWML' => $swmlContent];
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
        // Python wraps {"join_room": {name}} in a full SWML document.
        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['join_room' => ['name' => $name]],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    public function sipRefer(string $toUri): self
    {
        // Python wraps {"sip_refer": {to_uri}} in a full SWML document.
        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['sip_refer' => ['to_uri' => $toUri]],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    /**
     * Start background call tap using SWML.
     *
     * Full parity with the Python reference `tap`: validates direction
     * ({speak,hear,both}), codec ({PCMU,PCMA}) and rtp_ptime (> 0); the only
     * always-emitted field is `uri`; control_id/direction/codec/rtp_ptime/
     * status_url are added only when they differ from their defaults; the
     * {"tap": ...} verb is wrapped in a full SWML document via executeSwml.
     *
     * @param TapDirection|string $direction stream direction — the typed
     *   {@see TapDirection} enum (typo-checked at the call site) or a bare
     *   string (parity with Python's `tap`). Normalized to the wire string
     *   ('speak'/'hear'/'both').
     * @throws \InvalidArgumentException on invalid direction, codec, or rtp_ptime.
     */
    public function tap(
        string $uri,
        ?string $controlId = null,
        TapDirection|string $direction = 'both',
        string $codec = 'PCMU',
        int $rtpPtime = 20,
        ?string $statusUrl = null
    ): self {
        $directionStr = $direction instanceof TapDirection ? $direction->value : $direction;

        // Validate direction.
        $validDirections = ['speak', 'hear', 'both'];
        if (!in_array($directionStr, $validDirections, true)) {
            throw new \InvalidArgumentException(
                'direction must be one of ' . self::pythonList($validDirections)
            );
        }

        // Validate codec.
        $validCodecs = ['PCMU', 'PCMA'];
        if (!in_array($codec, $validCodecs, true)) {
            throw new \InvalidArgumentException(
                'codec must be one of ' . self::pythonList($validCodecs)
            );
        }

        // Validate rtp_ptime.
        if ($rtpPtime <= 0) {
            throw new \InvalidArgumentException('rtp_ptime must be a positive integer');
        }

        // Only `uri` is always present; everything else is emitted only when
        // it differs from its default (parity with the reference).
        $tapObj = ['uri' => $uri];

        if ($controlId !== null && $controlId !== '') {
            $tapObj['control_id'] = $controlId;
        }
        if ($directionStr !== 'both') {
            $tapObj['direction'] = $directionStr;
        }
        if ($codec !== 'PCMU') {
            $tapObj['codec'] = $codec;
        }
        if ($rtpPtime !== 20) {
            $tapObj['rtp_ptime'] = $rtpPtime;
        }
        if ($statusUrl !== null && $statusUrl !== '') {
            $tapObj['status_url'] = $statusUrl;
        }

        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['tap' => $tapObj],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    public function stopTap(?string $controlId = null): self
    {
        // Python wraps {"stop_tap": {...}} in a SWML document; {} when no
        // control_id is supplied.
        $stopParams = ($controlId !== null && $controlId !== '')
            ? ['control_id' => $controlId]
            : new \stdClass();

        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['stop_tap' => $stopParams],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    /**
     * Send a text message to a PSTN phone number using SWML.
     *
     * Full parity with the Python reference `send_sms`: either body or media
     * (or both) must be provided; the {"send_sms": ...} verb is wrapped in a
     * full SWML document via executeSwml. Optional fields (body/media/tags/
     * region) are emitted only when supplied.
     *
     * @param list<string> $media URLs to send (optional if $body is given).
     * @param list<string> $tags  tags for UI searching.
     * @throws \InvalidArgumentException if neither body nor media is provided.
     */
    public function sendSms(
        string $toNumber,
        string $fromNumber,
        ?string $body = null,
        array $media = [],
        array $tags = [],
        ?string $region = null
    ): self {
        // Validate that at least body or media is provided.
        if (($body === null || $body === '') && empty($media)) {
            throw new \InvalidArgumentException('Either body or media must be provided');
        }

        $sms = [
            'to_number' => $toNumber,
            'from_number' => $fromNumber,
        ];

        if ($body !== null && $body !== '') {
            $sms['body'] = $body;
        }
        if (!empty($media)) {
            $sms['media'] = $media;
        }
        if (!empty($tags)) {
            $sms['tags'] = $tags;
        }
        if ($region !== null && $region !== '') {
            $sms['region'] = $region;
        }

        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['send_sms' => $sms],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    /**
     * Process payment using the SWML pay action.
     *
     * Full parity with the Python reference `pay` (19 user-facing params): the
     * SWML document runs `{set:{ai_response}}` then `{pay:{...}}`. The wire key
     * for the collection method is `input` (NOT `input_method`); numeric and
     * boolean fields are stringified ("5"/"true"); `postal_code` is emitted as a
     * lowercased bool-string when a bool, or verbatim when a string. Optional
     * fields (status_url/charge_amount/description/parameters/prompts) are
     * emitted only when supplied.
     *
     * @param bool|string                  $postalCode      prompt-for-postal (bool) or an actual postcode (string).
     * @param list<array<string,string>>   $parameters      name/value pairs for the connector.
     * @param list<array<string,mixed>>    $prompts         custom prompt configs.
     */
    public function pay(
        string $paymentConnectorUrl,
        string $inputMethod = 'dtmf',
        ?string $statusUrl = null,
        string $paymentMethod = 'credit-card',
        int $timeout = 5,
        int $maxAttempts = 1,
        bool $securityCode = true,
        bool|string $postalCode = true,
        int $minPostalCodeLength = 0,
        string $tokenType = 'reusable',
        ?string $chargeAmount = null,
        string $currency = 'usd',
        string $language = 'en-US',
        string $voice = 'woman',
        ?string $description = null,
        string $validCardTypes = 'visa mastercard amex',
        ?array $parameters = null,
        ?array $prompts = null,
        ?string $aiResponse = 'The payment status is ${pay_result}, do not mention anything else about collecting payment if successful.'
    ): self {
        $payParams = [
            'payment_connector_url' => $paymentConnectorUrl,
            'input' => $inputMethod,
            'payment_method' => $paymentMethod,
            'timeout' => (string) $timeout,
            'max_attempts' => (string) $maxAttempts,
            'security_code' => $securityCode ? 'true' : 'false',
            'min_postal_code_length' => (string) $minPostalCodeLength,
            'token_type' => $tokenType,
            'currency' => $currency,
            'language' => $language,
            'voice' => $voice,
            'valid_card_types' => $validCardTypes,
        ];

        // postal_code: lowercased bool-string when a bool, verbatim when a string.
        if (is_bool($postalCode)) {
            $payParams['postal_code'] = $postalCode ? 'true' : 'false';
        } else {
            $payParams['postal_code'] = $postalCode;
        }

        if ($statusUrl !== null && $statusUrl !== '') {
            $payParams['status_url'] = $statusUrl;
        }
        if ($chargeAmount !== null && $chargeAmount !== '') {
            $payParams['charge_amount'] = $chargeAmount;
        }
        if ($description !== null && $description !== '') {
            $payParams['description'] = $description;
        }
        if (!empty($parameters)) {
            $payParams['parameters'] = $parameters;
        }
        if (!empty($prompts)) {
            $payParams['prompts'] = $prompts;
        }

        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['set' => ['ai_response' => $aiResponse]],
                    ['pay' => $payParams],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    // ── RPC ──────────────────────────────────────────────────────────────

    /**
     * Execute an RPC method on a call using SWML.
     *
     * Parity with the Python reference `execute_rpc`: the rpc params are
     * keyed {method, call_id?, node_id?, params?} (call_id/node_id are
     * TOP-LEVEL siblings of method/params, NOT nested inside params) and the
     * {"execute_rpc": ...} verb is wrapped in a full SWML document. There is
     * no `jsonrpc` envelope — method strings are bare (e.g. "dial", not
     * "calling.dial").
     *
     * @param array<string,mixed>|null $params optional RPC method parameters.
     */
    public function executeRpc(
        string $method,
        ?array $params = null,
        ?string $callId = null,
        ?string $nodeId = null
    ): self {
        $rpcParams = ['method' => $method];

        if ($callId !== null && $callId !== '') {
            $rpcParams['call_id'] = $callId;
        }
        if ($nodeId !== null && $nodeId !== '') {
            $rpcParams['node_id'] = $nodeId;
        }
        if (!empty($params)) {
            $rpcParams['params'] = $params;
        }

        $swmlDoc = [
            'version' => '1.0.0',
            'sections' => [
                'main' => [
                    ['execute_rpc' => $rpcParams],
                ],
            ],
        ];

        return $this->executeSwml($swmlDoc);
    }

    /**
     * Dial out to a number with a destination SWML URL using execute_rpc.
     * Parity: method="dial", params={devices:{type,params:{to_number,
     * from_number}}, dest_swml}.
     */
    public function rpcDial(
        string $toNumber,
        string $fromNumber,
        string $destSwml,
        string $deviceType = 'phone'
    ): self {
        return $this->executeRpc('dial', [
            'devices' => [
                'type' => $deviceType,
                'params' => [
                    'to_number' => $toNumber,
                    'from_number' => $fromNumber,
                ],
            ],
            'dest_swml' => $destSwml,
        ]);
    }

    /**
     * Inject a message into an AI agent on another call using execute_rpc.
     * Parity: method="ai_message", call_id top-level, params={role,
     * message_text}. `role` defaults to "system".
     */
    public function rpcAiMessage(string $callId, string $messageText, string $role = 'system'): self
    {
        return $this->executeRpc('ai_message', [
            'role' => $role,
            'message_text' => $messageText,
        ], $callId);
    }

    /**
     * Unhold another call using execute_rpc.
     * Parity: method="ai_unhold", call_id top-level, params={}.
     */
    public function rpcAiUnhold(string $callId): self
    {
        return $this->executeRpc('ai_unhold', [], $callId);
    }

    public function simulateUserInput(string $text): self
    {
        // Python action name is "user_input" (not "simulate_user_input").
        $this->actions[] = ['user_input' => $text];
        return $this;
    }

    // ── Payment Helpers (static) ─────────────────────────────────────────

    /**
     * Create a payment-prompt structure for use with {@see FunctionResult::pay()}.
     * Parity with the Python reference `create_payment_prompt`:
     * {"for": $forSituation, "actions": $actions, "card_type"?, "error_type"?}.
     *
     * @param list<array<string,string>> $actions   actions with 'type'/'phrase' keys.
     * @param string|null                $cardType  space-separated card types.
     * @param string|null                $errorType space-separated error types.
     * @return array<string,mixed>
     */
    public static function createPaymentPrompt(
        string $forSituation,
        array $actions,
        ?string $cardType = null,
        ?string $errorType = null
    ): array {
        $prompt = [
            'for' => $forSituation,
            'actions' => $actions,
        ];

        if ($cardType !== null && $cardType !== '') {
            $prompt['card_type'] = $cardType;
        }
        if ($errorType !== null && $errorType !== '') {
            $prompt['error_type'] = $errorType;
        }

        return $prompt;
    }

    /**
     * Create a payment action for use in payment prompts.
     * Parity: {"type": $actionType, "phrase": $phrase}.
     *
     * @return array<string,string>
     */
    public static function createPaymentAction(string $actionType, string $phrase): array
    {
        return [
            'type' => $actionType,
            'phrase' => $phrase,
        ];
    }

    /**
     * Create a payment parameter (name/value pair) for {@see FunctionResult::pay()}.
     * Parity: {"name": $name, "value": $value}.
     *
     * @return array<string,string>
     */
    public static function createPaymentParameter(string $name, string $value): array
    {
        return [
            'name' => $name,
            'value' => $value,
        ];
    }
}
