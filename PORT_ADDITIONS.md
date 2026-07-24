# PORT_ADDITIONS — PHP-only public symbols with no Python equivalent

<!-- ══════════════════════════════════════════════════════════════════════════
BEFORE YOU ADD AN ENTRY TO THIS FILE — READ THIS.

Every entry here is a place the parity checker STOPS comparing. That is a real cost:
a divergence you list is a divergence no gate will ever catch again. So entries must
be RARE, and each one must earn its place. Default to skepticism: assume the entry is
NOT needed and make the case that it is.

The order of preference, always:
  1. FIX THE PORT so it matches the reference (add the missing member; make the
     signature match).
  2. FIX THE EMISSION so idiom folds onto the reference shape — the enumerator/emitter
     canonicalizes your language's spelling onto the oracle's (builder → __init__,
     getters → attributes, Result<T,E> → the plain return, CamelCase → the reference
     name, options-object/kwargs → the expanded param list, RAII/dispose → close).
     MOST divergences are idiom and belong here, not in this file.
  3. FIX THE REFERENCE if the oracle itself is wrong or stale (a Python-only symbol
     that leaked into the contract, a param the reference added and the oracle never
     re-enumerated). Fix Python / the oracle, then re-drift — do not paper over a
     broken reference with a per-port entry.
  4. Only when 1–3 genuinely cannot apply does an entry here become justified.

An entry is JUSTIFIED ONLY IF it is irreducible after correct emission — i.e. the
divergence survives because the two languages genuinely cannot express the same thing,
not because the emitter hasn't folded the idiom yet. If emission COULD fold it, the
entry is a bug in this file; go fix the emitter.

Each entry MUST state WHY, concretely, in one of these forms:
  • ADDITION — this symbol exists in the port but not the reference. Answer: is it
    genuine port-only surface with NO reference twin (say what it is and why the
    reference has no equivalent), or is it IDIOM the emitter should have folded (then
    it does not belong here — fold it)? A convenience/alias/back-compat wrapper is NOT
    a justification.
  • OMISSION — this reference symbol has no port member. Answer: WHY can it not exist
    here — what specific language feature is absent (e.g. no async-context-manager
    protocol, no __init__ method protocol)? "impossible:" means the construct cannot
    be expressed at all; if it merely LOOKS different, that's idiom → fold it, don't
    omit it. Cite a precedent when one exists (e.g. RelayClient omits the same dunder).
  • SIGNATURE — the symbol matches by name but its parameters differ. Answer: is the
    difference a foldable idiom collapse (options-object, leading context/self,
    builder) — then EXPAND it in the signature emitter so names+count match, don't list
    it — or a genuine reference-only parameter with no cross-language analogue?

If you cannot write a crisp, specific WHY that survives the "could emission fold this?"
test, the entry is not ready. Prove it's needed before you add it.
═══════════════════════════════════════════════════════════════════════════════ -->


Symbols here exist in the PHP SDK but have no matching entry in the Python reference. These fall into three buckets:
  1. PHP-idiomatic accessors (getX(), setX(), explicit getters).
  2. Method-name variants where PHP's idiom differs from Python.
  3. Refactors where PHP merged Python's split classes (`*Namespace` vs `*Resource`).

Every entry must carry a rationale. Reviewers use this file to catch accidental additions.


# Format: `<fully.qualified.symbol>: <rationale>`
# Regenerate with `python3 scripts/generate_exemptions.py` after
# a surface change.

signalwire.SignalWire: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.SignalWire.add_skill_directory: PHP-class-method: PHP's SignalWire static facade hosts package-level helpers as static methods (mirrors Python's module-level signalwire.<name> free functions)
signalwire.SignalWire.get_logger: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.SignalWire.list_skills: PHP-class-method: PHP's SignalWire static facade hosts package-level helpers as static methods (mirrors Python's module-level signalwire.<name> free functions); this is the host for the signalwire.list_skills free function (see PORT_OMISSIONS.md).
signalwire.SignalWire.list_skills_with_params: PHP-class-method: PHP's SignalWire static facade hosts package-level helpers as static methods (mirrors Python's module-level signalwire.<name> free functions)
signalwire.SignalWire.register_skill: PHP-class-method: PHP's SignalWire static facade hosts package-level helpers as static methods (mirrors Python's module-level signalwire.<name> free functions)
signalwire.SignalWire.rest_client: PHP-class-method: PHP's SignalWire static facade hosts package-level helpers as static methods (mirrors Python's module-level signalwire.<name> free functions)
signalwire.agent_server.AgentServer.get_host: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.get_port: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.get_sip_auto_map: PHP idiomatic accessor / lifecycle hook on AgentServer (returns the auto_map flag passed to setupSipRouting).
signalwire.agent_server.AgentServer.get_sip_route: PHP idiomatic accessor / lifecycle hook on AgentServer (returns the SIP route configured via setupSipRouting).
signalwire.agent_server.AgentServer.get_sip_username_mapping: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.handle_request: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.is_sip_routing_enabled: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.serve: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agents.bedrock.BedrockAgent.render_swml: PHP override surfaces the public renderSwml() SWML-render entry on BedrockAgent (transforms the base `ai` verb into `amazon_bedrock`). Python's reference declares this as the private `_render_swml` (not on the surface); the same capability is public in PHP, mirroring the base AgentBase.render_swml addition.
signalwire.cli.simulation.mock_env.Adapter: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.cli.simulation.mock_env.Adapter.detect: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.detect_mode: PHP-specific platform-mode-detection adapter; the typed counterpart of detect() returning the ExecutionMode enum (same logic, autocompletion + exhaustive match). Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_azure: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_cgi: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_gcf: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_lambda: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.serve: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.core.agent_base.AgentBase.build_ai_verb: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.clone_for_request: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.get_mcp_servers: php_accessor: explicit read-only accessor returning the configured external MCP servers; Python exposes the same list via the private _mcp_servers attribute (no public getter). Pairs with add_mcp_server.
signalwire.core.agent_base.AgentBase.is_mcp_server_enabled: php_accessor: explicit boolean accessor reporting whether the /mcp endpoint is enabled; Python exposes the same flag via the private _mcp_server_enabled attribute (no public getter). Pairs with enable_mcp_server.
signalwire.core.agent_base.AgentBase.list_tool_names: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.render_swml: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_summary_callback: PHP-additive convenience: registers a post-prompt summary callback without subclassing. The canonical contract is the overridable on_summary(summary, raw_data) handler (present, parity); this registrar is a PHP idiom (both the callback and the overridable method run). No Python/TS equivalent.
signalwire.core.auth_handler.AuthHandler.middleware: php_native_middleware: framework-agnostic middleware analog on AuthHandler — the PHP-native counterpart to Python's framework-coupled get_fastapi_dependency / flask_decorator (both omitted; see PORT_OMISSIONS.md) and the role TS's middleware/expressMiddleware play. Returns a closure that rejects an unauthenticated request with a [status, headers, body] triple (matching Service::handleRequest's shape) or null to allow it through.
signalwire.core.auth_handler.AuthHandler.validate: php_native_middleware: header-validation helper backing AuthHandler::middleware (checks Bearer/API-key/Basic in order); the PHP-native analog of TS's AuthHandler.validate, used by the framework-agnostic middleware. Python performs the equivalent inline inside its framework dependency/decorator.
signalwire.core.contexts.Context.create_simple_context: PHP-class-method: hosts the module-level signalwire.core.contexts.create_simple_context free function as a static factory on Context (PSR-4; mirrors Python's module-level free function). See PORT_OMISSIONS.md.
signalwire.core.contexts.Context.get_initial_step: PHP idiomatic getter on Context.
signalwire.core.contexts.Context.get_name: PHP idiomatic getter on Context.
signalwire.core.contexts.Context.get_step_order: PHP idiomatic getter on Context.
signalwire.core.contexts.Context.get_steps: PHP idiomatic getter on Context.
signalwire.core.contexts.Context.get_valid_contexts: PHP idiomatic getter on Context.
signalwire.core.contexts.ContextBuilder.attach_tool_name_supplier: PHP idiomatic accessor on ContextBuilder.
signalwire.core.contexts.ContextBuilder.create_simple_context: PHP idiomatic accessor on ContextBuilder.
signalwire.core.contexts.ContextBuilder.has_contexts: PHP idiomatic accessor on ContextBuilder.
signalwire.core.contexts.GatherInfo.get_completion_action: PHP idiomatic getter on GatherInfo.
signalwire.core.contexts.GatherInfo.get_questions: PHP idiomatic getter on GatherInfo.
signalwire.core.contexts.GatherQuestion.get_key: PHP idiomatic getter on GatherQuestion.
signalwire.core.contexts.Step.get_functions: PHP idiomatic getter on Step.
signalwire.core.contexts.Step.get_gather_info: PHP idiomatic getter on Step.
signalwire.core.contexts.Step.get_name: PHP idiomatic getter on Step.
signalwire.core.contexts.Step.get_valid_contexts: PHP idiomatic getter on Step.
signalwire.core.contexts.Step.get_valid_steps: PHP idiomatic getter on Step.
signalwire.core.data_map.DataMap.create_expression_tool: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.data_map.DataMap.create_simple_api_tool: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.function_result.FunctionResult.to_json: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.logging_config.Logger: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.logging_config.Logger.debug: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.error: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.get_level: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.get_logger: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.get_name: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.info: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.is_suppressed: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.reset: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.set_level: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.set_suppressed: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.should_log: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.logging_config.Logger.warn: PHP wraps the Logger class with explicit getters/setters and level-named helpers (info, warn, error, debug); Python re-exports stdlib logging directly.
signalwire.core.security.session_manager.SessionManager.get_token_expiry_secs: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.security.session_manager.SessionManager.set_debug_mode: php_accessor: explicit setter gating debug_token (mirrors Python's _debug_mode attribute, which the reference toggles by direct attribute assignment). PHP exposes it as a public setter (no public attribute).
signalwire.web.web_service.WebService.handle_request: php_native_serving: the real static-file serving core for WebService, returning the [status, headers, body] triple used across the SDK. Python couples serving to FastAPI routes and TS to Hono handlers; PHP has no bundled web framework, so the dispatch lives in this native method that start() fronts with a socket bind. Same request→response behaviour, PHP idiom.
signalwire.core.security.webhook_middleware.WebhookMiddleware: PHP-idiom: PHP has no FastAPI/PSR-15 dominant framework, so webhook validation ships as a callable middleware class (`process(method, url, headers, rawBody, next): [status, headers, body]`) wired into Service::handleRequest. Functional parity with Python's `make_webhook_validation_dependency` FastAPI factory.
signalwire.core.security.webhook_middleware.WebhookMiddleware.__init__: PHP-idiom: WebhookMiddleware constructor takes the signing key — see WebhookMiddleware entry above.
signalwire.core.security.webhook_middleware.WebhookMiddleware.process: PHP-idiom: invocation method on the callable middleware class — see WebhookMiddleware entry above; process() is the object-shaped wrapper over the decomposed validate() core. The framework-free core `validate(method, url, headers, body, *, signing_key) -> optional<triple>` is hosted as the static method WebhookMiddleware::validate and projected onto the module-level oracle function `webhook_middleware.validate` (via FREE_FUNCTION_PROJECTIONS in both enumerators), so it reconciles EQUAL and is NOT an addition.
signalwire.core.skill_base.SkillBase.get_name: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase; Python's SkillBase declares SKILL_NAME as a class attribute rather than a get_name() accessor.
signalwire.core.skill_base.SkillBase.get_description: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase; Python's SkillBase declares SKILL_DESCRIPTION as a class attribute rather than a get_description() accessor.
signalwire.core.skill_base.SkillBase.get_required_env_vars: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase.
signalwire.core.skill_base.SkillBase.get_version: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase.
signalwire.core.skill_base.SkillBase.supports_multiple_instances: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase.
signalwire.skills.skill_name.SkillName: php_enum_idiom: PHP 8.1 backed enum modelling the built-in SkillName closed set as a type alongside the bare string (the Python reference uses bare str). Class-level addition; see SkillName.cases for the call-site rationale.
signalwire.logging.log_level.LogLevel: php_enum_idiom: PHP 8.1 backed enum modelling the LogLevel closed set (debug/info/warn/error) as a type alongside the bare string. Class-level addition; see LogLevel.cases for the call-site rationale.
signalwire.swaig.tap_direction.TapDirection: php_enum_idiom: PHP 8.1 backed enum modelling the SWAIG tap-direction closed set (speak/hear/both) as a type alongside the bare string. Class-level addition; see TapDirection.cases for the call-site rationale.
signalwire.swaig.record_direction.RecordDirection: php_enum_idiom: PHP 8.1 backed enum modelling the SWAIG record-direction closed set (speak/listen/both) as a type alongside the bare string. Class-level addition; see RecordDirection.cases for the call-site rationale.
signalwire.swaig.record_format.RecordFormat: php_enum_idiom: PHP 8.1 backed enum modelling the SWAIG record-format closed set (wav/mp3/mp4) as a type alongside the bare string. Class-level addition; see RecordFormat.cases for the call-site rationale.
signalwire.swaig.codec.Codec: php_enum_idiom: PHP 8.1 backed enum modelling the SWAIG tap-codec closed set (PCMU/PCMA) as a type alongside the bare string. Class-level addition; see Codec.cases for the call-site rationale.
signalwire.serverless.execution_mode.ExecutionMode: php_enum_idiom: PHP 8.1 backed enum modelling the Adapter execution-mode closed set (lambda/gcf/azure/cgi/server) as a type alongside the bare string. PORT-ONLY: Python has no equivalent enum. Class-level addition; see ExecutionMode.cases for the call-site rationale.
signalwire.serverless.execution_mode.ExecutionMode.is_serverless: php_enum_idiom: convenience predicate on the ExecutionMode enum — true for every mode except Server. PORT-ONLY (no Python equivalent); see ExecutionMode.cases.
signalwire.serverless.execution_mode.ExecutionMode.coerce: php_enum_idiom: PHP convenience static on the ExecutionMode enum that normalises ExecutionMode|string to the enum (validating the string against the closed set), backing the enum-OR-string acceptance on Adapter::serve(). See ExecutionMode.cases.
signalwire.relay.call_state.CallState: php_relay_state_enum: Tier-3 PHP 8.1 backed enum modelling the RELAY call-lifecycle closed set (created/ringing/answered/ending/ended) as a type alongside the bare string. Grounded in Constants::CALL_STATE_* (Python's relay/constants.py CALL_STATES). Offered ALONGSIDE Call::$state (the canonical string, parity) via the typed Call::callState() accessor. PORT-ONLY: the Python reference models call state as a bare str. Class-level addition; see CallState.is_terminal / CallState.try_from_wire. Deliberately NOT unified with DialState or MessageState — three distinct vocabularies.
signalwire.relay.call_state.CallState.is_terminal: php_relay_state_enum: predicate on the CallState enum — true only for Ended, matching Constants::CALL_TERMINAL_STATES (the state that resolves all in-flight actions). PORT-ONLY (see CallState).
signalwire.relay.call_state.CallState.try_from_wire: php_relay_state_enum: graceful coerce on the CallState enum — maps a wire string (or null) to the enum, returning null for an unknown/forward-compat server value instead of throwing (these mirror server-emitted values that can grow). PORT-ONLY (see CallState).
signalwire.relay.dial_state.DialState: php_relay_state_enum: Tier-3 PHP 8.1 backed enum modelling the RELAY dial-outcome closed set (dialing/answered/failed/no_answer/busy) as a type alongside the bare string. Grounded in Constants::DIAL_STATE_* + the no_answer/busy terminal failures Client::handleDialEvent processes (Python's relay/client.py documents dialing|answered|failed). Offered ALONGSIDE Event::getParams()['dial_state'] via the typed Event::dialState() accessor. PORT-ONLY: the Python reference reads dial_state as a bare str. Class-level addition; see DialState.is_terminal / DialState.try_from_wire. Deliberately NOT unified with CallState or MessageState — three distinct vocabularies (answered/failed recur but mean different things).
signalwire.relay.dial_state.DialState.is_terminal: php_relay_state_enum: predicate on the DialState enum — true for every outcome except Dialing (i.e. a winner answered or the dial gave up), matching Client::handleDialEvent's resolve/reject points. PORT-ONLY (see DialState).
signalwire.relay.dial_state.DialState.try_from_wire: php_relay_state_enum: graceful coerce on the DialState enum — maps a wire string (or null) to the enum, returning null for an unknown/forward-compat server value instead of throwing. PORT-ONLY (see DialState).
signalwire.relay.message_state.MessageState: php_relay_state_enum: Tier-3 PHP 8.1 backed enum modelling the RELAY message-delivery closed set (queued/initiated/sent/delivered/undelivered/failed/received) as a type alongside the bare string. Grounded in Constants::MESSAGE_STATE_* + MESSAGE_TERMINAL_STATES (Python's relay/constants.py). Offered ALONGSIDE Message::getState() (the canonical string, parity) via the typed Message::messageState() accessor. PORT-ONLY: the Python reference models message state as a bare str. Class-level addition; see MessageState.is_terminal / MessageState.try_from_wire. Deliberately NOT unified with CallState or DialState — three distinct vocabularies (failed recurs but means message-delivery failure here).
signalwire.relay.message_state.MessageState.is_terminal: php_relay_state_enum: predicate on the MessageState enum — true for Delivered/Undelivered/Failed, matching Constants::MESSAGE_TERMINAL_STATES (the states Message::dispatchEvent auto-resolves on); Received is NOT terminal (mirrors Python). PORT-ONLY (see MessageState).
signalwire.relay.message_state.MessageState.try_from_wire: php_relay_state_enum: graceful coerce on the MessageState enum — maps a wire string (or null) to the enum, returning null for an unknown/forward-compat server value instead of throwing. PORT-ONLY (see MessageState).
signalwire.relay.call.Call.call_state: php_relay_state_enum: typed accessor returning the call's current lifecycle state as a ?CallState (null when the raw string is outside the known set). Offered ALONGSIDE the canonical bare-string Call::$state for parity. PORT-ONLY: the Python reference exposes only the str state. See CallState.
signalwire.relay.message.Message.message_state: php_relay_state_enum: typed accessor returning the message's current delivery state as a ?MessageState (null when the raw string is outside the known set). Offered ALONGSIDE the canonical bare-string Message::getState() for parity. PORT-ONLY: the Python reference exposes only the str state. See MessageState.
signalwire.relay.event.Event.dial_state: php_relay_state_enum: typed accessor returning a calling.call.dial event's outcome as a ?DialState, read from the dial_state (or legacy state) param (null when absent/unknown). Offered ALONGSIDE the raw params string for parity. PORT-ONLY: the Python reference reads dial_state as a bare str. See DialState.
signalwire.relay.device.Device: php_device_struct: Tier-3 immutable typed value object for the RELAY {type, params} device descriptor that recurs across connect/dial/tap/refer. Grounded in relay-protocol/calling.{connect,dial,tap,refer}.params.json. Types the SHAPE only — `type` stays a bare string (the device-type set is open, not schema-enumerated). Purely additive: every method still accepts the raw array; Device::toArray() is byte-identical to the hand-written {type,params} literal. PORT-ONLY: the Python reference passes the device as a raw dict. See Device.to_dict.
signalwire.relay.device.Device.__init__: php_device_struct: readonly constructor-promoted ctor (string $type, array $params = []) for the Device value object — consistent with the Wave-A Event immutability idiom. PORT-ONLY (see Device).
signalwire.relay.device.Device.to_dict: php_device_struct: renders the Device to its wire array {type, params} — byte-identical to the hand-written literal (toArray() in PHP; the surface enumerator canonicalises toArray->to_dict). This is what connect/dial/tap/refer put on the wire. PORT-ONLY (see Device).
signalwire.relay.device.Device.phone: php_device_struct: convenience static building the common PSTN phone device ({type:phone, params:{to_number, from_number, ...extra}}). PORT-ONLY (see Device).
signalwire.relay.device.Device.sip: php_device_struct: convenience static building a SIP endpoint device ({type:sip, params:{to, ...extra}}), grounded in calling.refer.params.json's device.params.to. PORT-ONLY (see Device).
signalwire.swaig.parameter_schema.ParameterSchema: php_param_builder_idiom: Tier-2 flagship — fluent, type-safe builder over the EXACT untyped JSON-Schema `properties`/`argument` wire shape that defineTool()/registerSwaigFunction() already accept. PORT-ONLY: Python's define_tool takes a bare Dict[str, Any] (parameters: JSON Schema), so there is no Python equivalent. Purely additive: the untyped-array path is unchanged; the builder produces byte-identical output (proven by ParameterSchemaTest). See ParameterSchema.create for the call-site rationale.
signalwire.swaig.parameter_schema.ParameterSchema.create: php_param_builder_idiom: static factory starting an empty ParameterSchema builder; chains property methods (->string/->number/->integer/->boolean/->enum/->array/->object) then ->toArray() (defineTool $parameters slot, byte-identical to the hand-written properties map) or ->toArgument() (registerSwaigFunction blob with schema-level required). PORT-ONLY: Python hand-writes the Dict[str, Any] schema. See ParameterSchema.
signalwire.swaig.parameter_schema.ParameterSchema.string: php_param_builder_idiom: adds a JSON-Schema `string` property (description + optional default/enum/format/required) to the builder; emits the same {type:string, description:…} fragment as the hand-written form. PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.number: php_param_builder_idiom: adds a JSON-Schema `number` property to the builder; same wire fragment as hand-writing {type:number, description:…}. PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.integer: php_param_builder_idiom: adds a JSON-Schema `integer` property to the builder; same wire fragment as hand-writing {type:integer, description:…}. PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.boolean: php_param_builder_idiom: adds a JSON-Schema `boolean` property to the builder; same wire fragment as hand-writing {type:boolean, description:…}. PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.enum: php_param_builder_idiom: adds a closed-set `string` property whose value must be one of the given list; integrates the Tier-1 typed enums (RecordFormat/RecordDirection/TapDirection/Codec) — pass ::cases() and each \BackedEnum is normalized to its ->value, producing the same {type:string, description:…, enum:[…]} fragment as the built-in Joke/ApiNinjasTrivia skills hand-write. PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.array: php_param_builder_idiom: adds a JSON-Schema `array` property with a typed items kind (string/number/integer/boolean/object, optional items_enum or nested items_schema); same {type:array, description:…, items:{…}} fragment as the hand-written form. PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.object: php_param_builder_idiom: adds a nested JSON-Schema `object` property described by another ParameterSchema (its required names render as a schema-level list at the nested level); same {type:object, description:…, properties:{…}} fragment as the hand-written form. PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.required: php_param_builder_idiom: flags one or more property names required (idempotent, order-preserving); surfaces as per-property 'required'=>true via toArray() (the Math/WikipediaSearch defineTool convention) or as a schema-level `required` list via toArgument() (the Joke/GoogleMaps registerSwaigFunction convention). PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.required_names: php_param_builder_idiom: returns the flagged-required property names in declaration order (introspection / nested-object composition helper). PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.to_dict: php_param_builder_idiom: returns the bare JSON-Schema `properties` map — the value defineTool()'s $parameters argument expects — byte-identical to the hand-written nested-array form (toArray() in PHP; the surface enumerator canonicalises toArray->to_dict). PORT-ONLY (see ParameterSchema.create).
signalwire.swaig.parameter_schema.ParameterSchema.to_argument: php_param_builder_idiom: returns the full `argument` object {type:object, properties:{…}, required:[…]} for the registerSwaigFunction() path — byte-identical to the hand-written argument blocks (Joke/ApiNinjasTrivia/GoogleMaps), with required at the argument level. PORT-ONLY (see ParameterSchema.create).
signalwire.core.swml_builder.Document: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_builder.Document.__init__: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.add_raw_verb: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.add_section: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.add_verb: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.add_verb_to_section: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.clear_section: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.get_verbs: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.get_version: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.has_section: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.render: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.render_pretty: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.reset: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.Document.to_dict: PHP idiomatic accessor / mutation method on the Document class; Python users build the same SWML through SWMLBuilder.
signalwire.core.swml_builder.SWMLBuilder.__call: PHP magic-method dispatcher that auto-vivifies the remaining schema verb names (denoise, goto, record, sleep, …) onto the builder, delegating to Service::addVerb. This is the PHP analog of Python SWMLBuilder's runtime `__getattr__` verb dispatch (recorded impossible in PORT_OMISSIONS); the explicit answer/hangup/play/ai/say/add_section/build/render/reset methods match the oracle verbatim.
signalwire.core.swml_service.SWMLService.__call: PHP magic-method dispatcher that routes auto-vivified verb names (answer, hangup, play, etc) to Document::addVerb. Python uses `__getattr__` instead.
signalwire.core.swml_service.SWMLService.define_tool: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.define_tools: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.dispatch_from_globals: PHP CGI-mode helper that reads from `$_SERVER` / `$_REQUEST` globals; Python's equivalent is the WSGI/ASGI adapter shipped with FastAPI.
signalwire.core.swml_service.SWMLService.get_all_functions: PHP-tool-registry: SWMLService exposes the internal tool-registry methods ('get_all_functions') as public API for testing and reflection; Python keeps the registry internal
signalwire.core.swml_service.SWMLService.get_basic_auth_credentials_with_source: PHP-auth-helper: SWMLService.get_basic_auth_credentials_with_source is the (creds, source) tuple variant; Python merges this into the include_source=True branch of get_basic_auth_credentials
signalwire.core.swml_service.SWMLService.get_full_url: PHP getter for the host:port:route URL; Python computes this internally but doesn't expose a separate accessor on SWMLService.
signalwire.core.swml_service.SWMLService.get_function: PHP-tool-registry: SWMLService exposes the internal tool-registry methods ('get_function') as public API for testing and reflection; Python keeps the registry internal
signalwire.core.swml_service.SWMLService.get_host: PHP getter exposing the bind host; Python uses the underlying uvicorn config directly.
signalwire.core.swml_service.SWMLService.get_name: PHP getter for the service name; Python users access via `service.name` attribute directly.
signalwire.core.swml_service.SWMLService.get_port: PHP getter for the bind port; Python uses the underlying uvicorn config directly.
signalwire.core.swml_service.SWMLService.get_proxy_url_base: PHP getter for the SWML proxy URL; Python users access via the `SWML_PROXY_URL_BASE` env var directly.
signalwire.core.swml_service.SWMLService.get_route: PHP getter for the bind route; Python users access via `service.route` attribute directly.
signalwire.core.swml_service.SWMLService.get_tool_names: PHP getter for the registered tool names list; Python users iterate the underlying `_tool_registry` dict.
signalwire.core.swml_service.SWMLService.get_tools: PHP getter for the full tool registry; Python exposes the same data via the registry's iter API.
signalwire.core.swml_service.SWMLService.has_function: PHP-tool-registry: SWMLService exposes the internal tool-registry methods ('has_function') as public API for testing and reflection; Python keeps the registry internal
signalwire.core.swml_service.SWMLService.on_function_call: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.on_swml_request: PHP-hook: SWMLService.on_swml_request is the standard hook for SWML callback handling; Python exposes equivalent via WebMixin.on_swml_request which projects to SWMLService
signalwire.core.swml_service.SWMLService.register_swaig_function: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.remove_function: PHP-tool-registry: SWMLService exposes the internal tool-registry methods ('remove_function') as public API for testing and reflection; Python keeps the registry internal
signalwire.core.swml_service.SWMLService.render: PHP method exposing the canonical `render()` entry point — Python uses `render_document` (not `render`) for the same purpose.
signalwire.core.swml_service.SWMLService.render_pretty: PHP convenience for human-readable JSON output; Python equivalent is `render_document(pretty=True)`.
signalwire.core.swml_service.SWMLService.render_swml: PHP alias for the SWML render path; equivalent of Python's `render_document`.
signalwire.core.swml_service.SWMLService.run: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.validate_basic_auth: PHP-auth-helper: SWMLService.validate_basic_auth is exposed as a public method on the service for downstream auth checks; Python wraps it inside AuthMixin
signalwire.logging.logging_config.LoggingConfig: PHP-logging-config: PHP groups logging-config helpers (configureLogging/getLogger/resetLoggingConfiguration/stripControlChars/getExecutionMode/isServerlessMode) on a LoggingConfig static class; Python ships them as module-level functions in signalwire.core.logging_config (projected via FREE_FUNCTION_PROJECTIONS)
signalwire.logging.logging_config.LoggingConfig.configure_logging: PHP-logging-config: static-method host for the Python module-level free function signalwire.core.logging_config.configure_logging (PSR-4 file-per-class; projected via FREE_FUNCTION_PROJECTIONS). See PORT_OMISSIONS.md.
signalwire.logging.logging_config.LoggingConfig.get_execution_mode: PHP-logging-config: PHP groups logging-config helpers (getExecutionMode/isServerlessMode) on a LoggingConfig static class; Python ships them as module-level functions in signalwire.core.logging_config (already projected via FREE_FUNCTION_PROJECTIONS)
signalwire.logging.logging_config.LoggingConfig.get_logger: PHP-logging-config: static-method host for the Python module-level free function signalwire.core.logging_config.get_logger (PSR-4 file-per-class; projected via FREE_FUNCTION_PROJECTIONS). See PORT_OMISSIONS.md.
signalwire.logging.logging_config.LoggingConfig.is_serverless_mode: PHP-logging-config: PHP groups logging-config helpers (getExecutionMode/isServerlessMode) on a LoggingConfig static class; Python ships them as module-level functions in signalwire.core.logging_config (already projected via FREE_FUNCTION_PROJECTIONS)
signalwire.logging.logging_config.LoggingConfig.reset_logging_configuration: PHP-logging-config: static-method host for the Python module-level free function signalwire.core.logging_config.reset_logging_configuration (PSR-4 file-per-class; projected via FREE_FUNCTION_PROJECTIONS). See PORT_OMISSIONS.md.
signalwire.logging.logging_config.LoggingConfig.strip_control_chars: PHP-logging-config: static-method host for the Python module-level free function signalwire.core.logging_config.strip_control_chars (PSR-4 file-per-class; projected via FREE_FUNCTION_PROJECTIONS). See PORT_OMISSIONS.md.
signalwire.prefabs.concierge.ConciergeAgent.get_amenities: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.concierge.ConciergeAgent.get_services: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.concierge.ConciergeAgent.get_venue_name: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.faq_bot.FAQBotAgent.get_faqs: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.faq_bot.FAQBotAgent.get_suggest_related: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.info_gatherer.InfoGathererAgent.get_questions: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.receptionist.ReceptionistAgent.get_departments: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.receptionist.ReceptionistAgent.get_greeting: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.survey.SurveyAgent.get_survey_name: PHP idiomatic accessor on the prefab class.
signalwire.prefabs.survey.SurveyAgent.get_survey_questions: PHP idiomatic accessor on the prefab class.
signalwire.relay.call.AIAction.get_stop_method: PHP idiomatic accessor on the AIAction subclass.
signalwire.relay.call.Action.execute_subcommand: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_call_id: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_control_id: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_events: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_node_id: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_payload: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_result: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_state: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.get_stop_method: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.handle_event: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.on_completed: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.resolve: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Action.stop: PHP idiomatic getter on Action; Python's Action class exposes the same data via direct attributes.
signalwire.relay.call.Call.dispatch_event: PHP idiomatic getter on Call; Python's Call class exposes the same data via direct attribute access.
signalwire.relay.call.Call.resolve_all_actions: PHP idiomatic getter on Call; Python's Call class exposes the same data via direct attribute access.
signalwire.relay.call.CollectAction.get_collect_result: PHP idiomatic accessor on the CollectAction subclass.
signalwire.relay.call.CollectAction.get_stop_method: PHP idiomatic accessor on the CollectAction subclass.
signalwire.relay.call.CollectAction.handle_event: PHP idiomatic accessor on the CollectAction subclass.
signalwire.relay.call.CollectAction.set_stop_method: PHP CollectAction is the handle for both calling.collect and calling.play_and_collect (Python uses two separate types); the setter lets startAction wire the right verb-specific stop sub-command.
signalwire.relay.call.DetectAction.get_detect_result: PHP idiomatic accessor on the DetectAction subclass.
signalwire.relay.call.DetectAction.get_stop_method: PHP idiomatic accessor on the DetectAction subclass.
signalwire.relay.call.FaxAction.get_fax_type: PHP idiomatic accessor on the FaxAction subclass.
signalwire.relay.call.FaxAction.get_stop_method: PHP idiomatic accessor on the FaxAction subclass.
signalwire.relay.call.PayAction.get_stop_method: PHP idiomatic accessor on the PayAction subclass.
signalwire.relay.call.PlayAction.get_stop_method: PHP idiomatic action accessor for play-controls (pause/resume/volume); Python exposes via Action subclass methods directly.
signalwire.relay.call.RecordAction.get_duration: PHP idiomatic accessor on the RecordAction subclass.
signalwire.relay.call.RecordAction.get_size: PHP idiomatic accessor on the RecordAction subclass.
signalwire.relay.call.RecordAction.get_stop_method: PHP idiomatic accessor on the RecordAction subclass.
signalwire.relay.call.RecordAction.get_url: PHP idiomatic accessor on the RecordAction subclass.
signalwire.relay.call.StreamAction.get_stop_method: PHP idiomatic accessor on the StreamAction subclass.
signalwire.relay.call.TapAction.get_stop_method: PHP idiomatic accessor on the TapAction subclass.
signalwire.relay.call.TranscribeAction.get_stop_method: PHP idiomatic accessor on the TranscribeAction subclass.
signalwire.relay.client.RelayClient.authenticate: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.get_call: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.get_calls: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.get_messages: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.handle_event: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.handle_message: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.read_once: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.reconnect: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.send: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.client.RelayClient.send_ack: PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); Python users access via direct attribute reads.
signalwire.relay.constants.Constants: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.relay.event.Event: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.relay.event.Event.__init__: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_call_id: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_control_id: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_event_type: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_node_id: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_params: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_state: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_tag: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.get_timestamp: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.parse: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.Event.to_dict: PHP idiomatic getter on Event; Python's per-event-type subclasses expose the same data as direct attributes.
signalwire.relay.event.CallReceiveEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.CallReceiveEvent.get_call_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallReceiveEvent.get_context: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallReceiveEvent.get_device: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallReceiveEvent.get_direction: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallReceiveEvent.get_node_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallReceiveEvent.get_project_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallReceiveEvent.get_segment_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallReceiveEvent.get_tag: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallStateEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.CallStateEvent.get_call_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallStateEvent.get_device: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallStateEvent.get_direction: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallStateEvent.get_end_reason: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallingErrorEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.CallingErrorEvent.get_code: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CallingErrorEvent.get_message: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CollectEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.CollectEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CollectEvent.get_final: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CollectEvent.get_result: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.CollectEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ConferenceEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.ConferenceEvent.get_conference_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ConferenceEvent.get_name: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ConferenceEvent.get_status: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ConnectEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.ConnectEvent.get_connect_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ConnectEvent.get_peer: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.DenoiseEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.DenoiseEvent.get_denoised: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.DetectEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.DetectEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.DetectEvent.get_detect: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.DialEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.DialEvent.get_call: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.DialEvent.get_dial_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.DialEvent.get_tag: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.EchoEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.EchoEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.FaxEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.FaxEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.FaxEvent.get_fax: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.HoldEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.HoldEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.MessageReceiveEvent.get_body: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_context: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_direction: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_from_number: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_media: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_message_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_message_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_segments: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_tags: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageReceiveEvent.get_to_number: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.MessageStateEvent.get_body: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_context: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_direction: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_from_number: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_media: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_message_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_message_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_reason: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_segments: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_tags: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.MessageStateEvent.get_to_number: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.PayEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.PayEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.PayEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.PlayEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.PlayEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.PlayEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.QueueEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.QueueEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.QueueEvent.get_position: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.QueueEvent.get_queue_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.QueueEvent.get_queue_name: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.QueueEvent.get_size: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.QueueEvent.get_status: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RecordEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.RecordEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RecordEvent.get_duration: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RecordEvent.get_record: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RecordEvent.get_size: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RecordEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RecordEvent.get_url: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ReferEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.ReferEvent.get_sip_notify_response_code: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ReferEvent.get_sip_refer_response_code: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ReferEvent.get_sip_refer_to: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.ReferEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RelayEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.RelayEvent.get_call_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RelayEvent.get_event_type: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RelayEvent.get_params: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RelayEvent.get_timestamp: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.RelayEvent.parse_event: php_class_host: parseEvent hosted as a static method on the base RelayEvent (PHP has no module-level free functions — PSR-4 file-per-class); projected to the Python module-level free function signalwire.relay.event.parse_event. Same 23 event-type dispatch as the reference.
signalwire.relay.event.SendDigitsEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.SendDigitsEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.SendDigitsEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.StreamEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.StreamEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.StreamEvent.get_name: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.StreamEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.StreamEvent.get_url: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TapEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.TapEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TapEvent.get_device: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TapEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TapEvent.get_tap: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TranscribeEvent.__init__: php_event_accessor: PHP constructor-promoted value object; the Python reference dataclass generates its __init__ implicitly (not surfaced). Purely additive — the typed event carries the same fields.
signalwire.relay.event.TranscribeEvent.get_control_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TranscribeEvent.get_duration: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TranscribeEvent.get_recording_id: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TranscribeEvent.get_size: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TranscribeEvent.get_state: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.event.TranscribeEvent.get_url: php_event_accessor: PHP idiomatic getter; the Python reference dataclass exposes the same field as a direct attribute (bare attribute access, not surfaced as a method). Purely additive, same wire data.
signalwire.relay.message.Message.dispatch_event: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_body: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_context: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_direction: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_from_number: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_media: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_message_id: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_reason: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_result: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_state: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_tags: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_to_number: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.handle_event: alias for dispatchEvent so the Client's event router (which symmetrically calls handleEvent on actions and messages) doesn't need a per-type branch.
signalwire.relay.message.Message.on_completed: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.resolve: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.web_socket.WebSocket: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.__init__: Port-internal WebSocket transport adapter (phrity/websocket-backed); constructor accepts an optional CA-bundle path for wss:// peer verification. Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.close: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.connect: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.is_connected: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.receive: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.send_text: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.rest._base.BaseResource.get_base_path: PHP idiomatic accessor on BaseResource — Python exposes the base path as the protected `_base_path` attribute; PHP wraps it in `getBasePath()`.
signalwire.rest._base.BaseResource.get_http: PHP idiomatic accessor on BaseResource — Python exposes the http transport as the protected `_http` attribute; PHP wraps it in `getHttp()`.
signalwire.rest._base.CrudWithAddresses.__init__: PHP exposes an explicit `__construct(client, basePath)` because PHP's reflection emits a constructor entry on every concrete class — Python's CrudWithAddresses inherits from CrudResource without redeclaring `__init__`.
signalwire.rest._base.CrudResource.__init__: PHP idiomatic accessor on CrudResource (PHP exposes `getBasePath()`, `getClient()`, `getProjectId()` for advanced use).
signalwire.rest._base.HttpClient.get_auth_header: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.get_base_url: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.get_project_id: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.get_token: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.list_all: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.SignalWireRestError.__str__: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.rest._base.SignalWireRestError.get_body: PHP getter for the response-body envelope field; Python's SignalWireRestError exposes `body` as a plain attribute (not a surface method)
signalwire.rest._base.SignalWireRestError.get_headers: PHP getter for the response-header map (§6.6 error-observability); Python's SignalWireRestError exposes `headers` as a plain attribute (not a surface method)
signalwire.rest._base.SignalWireRestError.get_method: PHP getter for the request-method envelope field; Python's SignalWireRestError exposes `method` as a plain attribute (not a surface method)
signalwire.rest._base.SignalWireRestError.get_request_id: PHP getter for the platform request-id pulled from the response headers (§6.6 error-observability); Python's SignalWireRestError exposes `request_id` as a plain attribute (not a surface method)
signalwire.rest._base.SignalWireRestError.get_response_body: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.rest._base.SignalWireRestError.get_status_code: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.rest._base.SignalWireRestError.get_url: PHP getter for the request-URL envelope field; Python's SignalWireRestError exposes `url` as a plain attribute (not a surface method)
signalwire.rest._request_options.RequestOptions.__init__: PHP-explicit-constructor: Python's RequestOptions is a @dataclass, so its synthesized __init__ is not on the reference SURFACE (griffe omits dataclass-generated inits). PHP has no dataclass — it declares an explicit __construct with the same five optional fields (timeout, retries, retry_on_status, retry_backoff, abort_signal). Same construction contract; only the dataclass-vs-explicit-constructor idiom differs.
signalwire.rest._pagination.PaginatedIterator.current: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.get_data_key: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.get_http: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.get_index: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.get_items: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.get_params: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.get_path: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.is_done: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.key: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.next: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.rewind: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest._pagination.PaginatedIterator.valid: PHP-iterator-protocol: PaginatedIterator implements PHP's Iterator interface (current/next/rewind/valid/key) and explicit getter methods (get_data_key/get_http/get_index/get_items/get_params/get_path/is_done) that Python expresses via direct attribute access on its iterator
signalwire.rest.client.RestClient.__get: PHP magic-method accessor: `$client->phoneNumbers` / `$client->fabric` property reads delegate to the same-named accessor method, so the python/stripe attribute-chain idiom (client.fabric.ai_agents) works verbatim in PHP. Python uses native attribute access; PHP has no surface member for it — the addition is the analog of SWMLService.__call.
signalwire.rest.client.RestClient.addresses: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.calling: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.chat: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.datasphere: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.fabric: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.get_base_url: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.get_http: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.get_project_id: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.get_space: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.get_token: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.imported_numbers: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.logs: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.lookup: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.messages: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.mfa: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.number_groups: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.phone_numbers: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.project: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.projects: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.pubsub: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.queues: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.recordings: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.registry: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.short_codes: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.sip_profile: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.verified_callers: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.video: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.custom_skills.skill.CustomSkillsSkill: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.custom_skills.skill.CustomSkillsSkill.get_description: PHP idiomatic accessor on CustomSkillsSkill.
signalwire.skills.custom_skills.skill.CustomSkillsSkill.get_name: PHP idiomatic accessor on CustomSkillsSkill.
signalwire.skills.custom_skills.skill.CustomSkillsSkill.register_tools: PHP idiomatic accessor on CustomSkillsSkill.
signalwire.skills.custom_skills.skill.CustomSkillsSkill.setup: PHP idiomatic accessor on CustomSkillsSkill.
signalwire.skills.custom_skills.skill.CustomSkillsSkill.supports_multiple_instances: PHP idiomatic accessor on CustomSkillsSkill.
signalwire.skills.datasphere.skill.DataSphereSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.datasphere.skill.DataSphereSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.datasphere.skill.DataSphereSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.datetime.skill.DateTimeSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.datetime.skill.DateTimeSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.google_maps.skill.GoogleMapsSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.google_maps.skill.GoogleMapsSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.http_helper.HttpHelper: PHP-internal HTTP helper used by the skill base class; Python skills use `requests` directly.
signalwire.skills.http_helper.HttpHelper.apply_base_url_override: PHP-internal HTTP helper used by the skill base class; Python skills use `requests` directly.
signalwire.skills.http_helper.HttpHelper.get: PHP-internal HTTP helper used by the skill base class; Python skills use `requests` directly.
signalwire.skills.http_helper.HttpHelper.post_json: PHP-internal HTTP helper used by the skill base class; Python skills use `requests` directly.
signalwire.skills.http_helper.HttpHelper.request: PHP-internal HTTP helper used by the skill base class; Python skills use `requests` directly.
signalwire.skills.info_gatherer.skill.InfoGathererSkill.get_description: PHP idiomatic accessor / public hook on InfoGathererSkill.
signalwire.skills.info_gatherer.skill.InfoGathererSkill.get_name: PHP idiomatic accessor / public hook on InfoGathererSkill.
signalwire.skills.info_gatherer.skill.InfoGathererSkill.get_prompt_sections: PHP idiomatic accessor / public hook on InfoGathererSkill.
signalwire.skills.info_gatherer.skill.InfoGathererSkill.supports_multiple_instances: PHP idiomatic accessor / public hook on InfoGathererSkill.
signalwire.skills.joke.skill.JokeSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.joke.skill.JokeSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.math.skill.MathSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.math.skill.MathSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.get_version: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.registry.SkillRegistry.get_external_paths: PHP-registry-getter: SkillRegistry.get_external_paths returns the configured external skill directories; Python keeps this as an internal _external_paths attribute
signalwire.skills.registry.SkillRegistry.get_factory: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.registry.SkillRegistry.instance: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.registry.SkillRegistry.reset: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.spider.skill.SpiderSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.spider.skill.SpiderSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.spider.skill.SpiderSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.weather_api.skill.WeatherApiSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.weather_api.skill.WeatherApiSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.web_search.skill.WebSearchSkill.get_description: PHP idiomatic accessor / public hook on WebSearchSkill; Python implements the same behavior via private helpers.
signalwire.skills.web_search.skill.WebSearchSkill.get_name: PHP idiomatic accessor / public hook on WebSearchSkill; Python implements the same behavior via private helpers.
signalwire.skills.web_search.skill.WebSearchSkill.get_version: PHP idiomatic accessor / public hook on WebSearchSkill; Python implements the same behavior via private helpers.
signalwire.skills.web_search.skill.WebSearchSkill.supports_multiple_instances: PHP idiomatic accessor / public hook on WebSearchSkill; Python implements the same behavior via private helpers.
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.utils.schema_utils.Schema: idiomatic PHP singleton sidecar; canonical SchemaUtils ships separately as SignalWire\Utils\SchemaUtils.
signalwire.utils.schema_utils.Schema.get_verb: PHP singleton sidecar accessor; canonical SchemaUtils ships separately.
signalwire.utils.schema_utils.Schema.get_verb_names: PHP singleton sidecar accessor; canonical SchemaUtils ships separately.
signalwire.utils.schema_utils.Schema.instance: PHP singleton accessor; canonical SchemaUtils ships separately.
signalwire.utils.schema_utils.Schema.is_valid_verb: PHP singleton sidecar accessor; canonical SchemaUtils ships separately.
signalwire.utils.schema_utils.Schema.reset: PHP singleton reset hook (test-only); canonical SchemaUtils ships separately.
signalwire.utils.schema_utils.Schema.verb_count: PHP singleton sidecar accessor; canonical SchemaUtils ships separately.
signalwire.utils.schema_utils.SchemaUtils.generate_method_body: Python-source codegen helper; canonical Python signatures filter this method out (Python-only output shape).
signalwire.utils.schema_utils.SchemaUtils.generate_method_signature: Python-source codegen helper; canonical Python signatures filter this method out (Python-only output shape).
signalwire.utils.schema_utils.SchemaValidationError.get_errors: PHP-exception-getter: SchemaValidationError exposes 'get_errors' as an explicit getter; Python attaches the same data as instance attributes accessed directly
signalwire.utils.schema_utils.SchemaValidationError.get_verb_name: PHP-exception-getter: SchemaValidationError exposes 'get_verb_name' as an explicit getter; Python attaches the same data as instance attributes accessed directly
signalwire.utils.url_validator.UrlValidator: PHP-class-host: PHP groups url_validator helpers on a UrlValidator static class for cohesion; Python ships validate_url as a module-level function (already projected to module-level free function via FREE_FUNCTION_PROJECTIONS)
signalwire.utils.url_validator.UrlValidator.validate_url: PHP-class-host: PHP groups url_validator helpers on a UrlValidator static class for cohesion; Python ships validate_url as a module-level function (already projected to module-level free function via FREE_FUNCTION_PROJECTIONS)

signalwire.core.agent_base.AgentBase.get_dynamic_config_callback: PHP-bean-accessor — PHP exposes the dynamic-config callback via getDynamicConfigCallback(); Python keeps it as a private _dynamic_config_callback attribute set/cleared by setDynamicConfigCallback (no public getter on the Python side)
signalwire.core.agent_base.AgentBase.get_signing_key: php_accessor: AgentBase exposes signing_key getter (Python keeps it private)
signalwire.core.security.security_utils.SecurityUtils: php_idiom_class_wrapper: static class hosting the security hygiene helpers (Python keeps filter_sensitive_headers/redact_url/is_valid_hostname at module level); static methods projected to module-level free functions via FREE_FUNCTION_PROJECTIONS
signalwire.core.security.security_utils.SecurityUtils.filter_sensitive_headers: php_idiom_class_wrapper: see SecurityUtils class entry
signalwire.core.security.security_utils.SecurityUtils.redact_url: php_idiom_class_wrapper: see SecurityUtils class entry
signalwire.core.security.security_utils.SecurityUtils.is_valid_hostname: php_idiom_class_wrapper: see SecurityUtils class entry
signalwire.rest._base.FabricResourcePUT.__init__: PHP reflection emits a constructor entry on every concrete class; FabricResourcePUT pins the update verb to PUT via its constructor. Python's FabricResourcePUT sets _update_method='PUT' as a class attribute without redeclaring __init__.
signalwire.rest._base.ReadResource.__init__: PHP reflection emits a constructor entry on every concrete class; Python's ReadResource inherits __init__ from BaseResource without redeclaring it.
signalwire.rest._base.ReadResource.get_client: PHP-idiom getter on ReadResource: exposes the HttpClient via getClient() for advanced use; Python uses the protected _http attribute.
signalwire.rest.namespaces._client_tree_generated.DatasphereNamespace.__get: PHP magic-method accessor (__get): namespace-container property reads ($client->datasphere->documents) delegate to the same-named accessor method, so the python attribute-chain idiom works verbatim. Python uses native attribute access; PHP has no surface member for it — analog of SWMLService.__call.
signalwire.rest.namespaces._client_tree_generated.FabricNamespace.__get: PHP magic-method accessor (__get): namespace-container property reads ($client->fabric->aiAgents) delegate to the same-named accessor method, so the python attribute-chain idiom works verbatim. Python uses native attribute access; PHP has no surface member for it — analog of SWMLService.__call.
signalwire.rest.namespaces._client_tree_generated.LogsNamespace.__get: PHP magic-method accessor (__get): namespace-container property reads delegate to the same-named accessor method, so the python attribute-chain idiom works verbatim. Python uses native attribute access; PHP has no surface member for it — analog of SWMLService.__call.
signalwire.rest.namespaces._client_tree_generated.ProjectNamespace.__get: PHP magic-method accessor (__get): namespace-container property reads delegate to the same-named accessor method, so the python attribute-chain idiom works verbatim. Python uses native attribute access; PHP has no surface member for it — analog of SWMLService.__call.
signalwire.rest.namespaces._client_tree_generated.RegistryNamespace.__get: PHP magic-method accessor (__get): namespace-container property reads ($client->registry->brands) delegate to the same-named accessor method, so the python attribute-chain idiom works verbatim. Python uses native attribute access; PHP has no surface member for it — analog of SWMLService.__call.
signalwire.rest.namespaces._client_tree_generated.VideoNamespace.__get: PHP magic-method accessor (__get): namespace-container property reads ($client->video->rooms) delegate to the same-named accessor method, so the python attribute-chain idiom works verbatim. Python uses native attribute access; PHP has no surface member for it — analog of SWMLService.__call.
signalwire.rest.namespaces.calling_resources_generated.Calling.get_base_path: PHP-idiom getter: explicit getBasePath() on the generated command-dispatch resource; Python exposes the base path as a class-level attribute. Same data, different access shape.
signalwire.rest._base.FabricResource.__init__: PHP reflection synthesizes a constructor entry on every concrete class (inherited from CrudWithAddresses); Python's FabricResource is an empty intermediate base that inherits __init__ without redeclaring it.

# --- Skill override methods PHP declares that the reference skill does not ---
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.get_prompt_sections: PHP's ClaudeSkills declares its own getPromptSections() override; the Python ClaudeSkillsSkill does not surface a get_prompt_sections member (it uses the SkillBase default without redeclaring). Idiomatic explicit override.

# --- LiveWire subsystem (item I) — LiveKit-compat shim, PHP hosting devices + idiomatic accessors ---

<!-- family-folded surface twins (wave-2 allowlist fold) -->
agentbase-family.build_ai_verb: PHP-only helper: AgentBase.build_ai_verb constructs the SWML `ai` verb block; a public port convenience with no Python reference twin. Family-folded twin of the unfolded AgentBase.build_ai_verb entry.
agentbase-family.clone_for_request: PHP-only helper: per-request agent cloning entry point (dynamic-config idiom); Python performs the equivalent internally. Family-folded twin of the unfolded AgentBase.clone_for_request entry.
agentbase-family.create_tool_token: PHP-additional-API: AgentBase exposes tool-token creation as a public method (paired with SessionManager); Python keeps token operations internal. Family-folded twin of the unfolded AgentBase.create_tool_token entry.
agentbase-family.get_contexts: PHP-prompt-getter: explicit prompt accessor on AgentBase (the composition-flatten of PromptManager.get_contexts, which IS emitted on PromptManager); Python exposes it via the delegate / direct attributes. Family-folded twin of the unfolded AgentBase.get_contexts entry.
agentbase-family.get_dynamic_config_callback: php_accessor: AgentBase exposes the dynamic-config callback via getDynamicConfigCallback(); Python keeps it as a private _dynamic_config_callback attribute. Family-folded twin of the unfolded AgentBase.get_dynamic_config_callback entry.
agentbase-family.get_mcp_servers: php_accessor: read-only accessor returning the configured external MCP servers; Python exposes the same list via the private _mcp_servers attribute (no public getter). Family-folded twin of the unfolded AgentBase.get_mcp_servers entry.
agentbase-family.get_raw_prompt: PHP-prompt-getter: explicit prompt accessor on AgentBase (composition-flatten of PromptManager.get_raw_prompt, which IS emitted on PromptManager); Python exposes it via the delegate / direct attributes. Family-folded twin of the unfolded AgentBase.get_raw_prompt entry.
agentbase-family.get_signing_key: php_accessor: AgentBase exposes a signing_key getter; Python keeps it private. Family-folded twin of the unfolded AgentBase.get_signing_key entry.
agentbase-family.is_mcp_server_enabled: php_accessor: boolean accessor reporting whether the /mcp endpoint is enabled; Python exposes the same flag via the private _mcp_server_enabled attribute. Family-folded twin of the unfolded AgentBase.is_mcp_server_enabled entry.
agentbase-family.list_tool_names: PHP-only helper: explicit accessor listing registered tool names; Python users introspect the registry directly. Family-folded twin of the unfolded AgentBase.list_tool_names entry.
agentbase-family.render_swml: PHP-public render entry: AgentBase.render_swml is public in PHP (delegates to SwmlRenderer); Python declares the equivalent as the private _render_swml (not surfaced). Family-folded twin of the unfolded AgentBase.render_swml / BedrockAgent.render_swml entries.
agentbase-family.set_summary_callback: PHP-additive convenience: registers a post-prompt summary callback without subclassing; the canonical contract is the overridable on_summary() handler (present, parity). No Python/TS equivalent. Family-folded twin of the unfolded AgentBase.set_summary_callback entry.
