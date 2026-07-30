# PORT_SIGNATURE_OMISSIONS.md

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


Documented signature divergences between this port and the Python reference. Every entry has a one-line rationale describing why the PHP shape is functionally equivalent. As of phase 4 cleanup, all `not_yet_implemented` entries have been closed; new entries should reuse one of the rationale categories below or a new `PHP-*` tag.

Categories used in rationales:
  * `PHP-idiom-kwargs` — PHP has no `**kwargs`; Python's variadic kwargs translate to PHP's `array $params` / `array $kwargs` positional argument.
  * `PHP-idiom-options-collapse` — PHP collapses Python's flat positional params into a single `array $options/$opts/$params` arg.
  * `PHP-idiom-options-trim` — PHP exposes a subset of params; the rest are configured via builder methods on the returned object.
  * `PHP-idiom-args-trim` / `PHP-idiom-args-rename` — PHP signature uses fewer args or renamed args; same call surface.
  * `PHP-idiom-noargs` — PHP method takes no args; Python's optional positional/kwargs are exposed via separate setters/configuration.
  * `PHP-idiom-fluent-builder` — PHP returns a builder for fluent chaining where Python takes/returns dicts.
  * `PHP-idiom-getter` / `PHP-idiom-internal` — Python `@property` accessor; PHP exposes as an explicit `get<Name>` accessor method (already in PORT_ADDITIONS) or keeps it internal.
  * `PHP-idiom-method-split` — PHP splits a multi-mode Python method into two named methods (e.g. `get_basic_auth_credentials` + `get_basic_auth_credentials_with_source`).
  * `PHP-callable-typing` — PHP's reflection emits a typed-callable class where Python uses canonical `callable<...>` shape; same call contract.
  * `PHP-pattern-typing` — PHP doesn't ship a Pattern class; regex stays a string.
  * `PHP-param-typing` — Single param's type encoding differs (string vs int vs bool); functional behavior identical.
  * `PHP-param-shape` — A param takes a different-but-equivalent structural shape (e.g. an ergonomic `array<string,bool>` map the method reshapes into the wire list Python takes directly); same wire output, different input ergonomics — tightening to the reference type would change what the PHP method accepts at runtime.
  * `PHP-typevar-blindspot` — Python types the member with a generic TypeVar (`_V`); PHP has no value-generic / TypeVar construct, so the exact Python-internal TypeVar identity is un-expressible even via a PHPDoc generic. Genuine language limit (ADAPTER_CONTRACT: a generic type variable not modelled).
  * `PHP-construction` — Constructor takes a different shape (typically `(params, client)` vs Python's flat positional fields).
  * `PHP-callback-shape` — Callback receives data via a single payload dict where Python passes positional args.
  * `PHP-rest-resource-typing` — PHP's REST namespace methods return generic `CrudResource`; Python returns per-resource subclass.
  * `PHP-fluent-api` — Method returns a builder for chaining where Python returns void.
  * `PHP-builder-api` — Method returns a builder/Document object where Python returns a serialized dict.
  * `PHP-event-typing` / `PHP-action-typing` / `PHP-return-typing` — Return type uses a different but compatible shape.
  * `PHP-additional-API` / `PHP-only` / `PHP-extra-param` — PHP exposes more surface than Python (auto-synthesized `__init__`, public helper methods, etc.).
  * `PHP-default-ctor` — PHP supplies a default constructor for every class; auto-synthesized `__init__` has no Python counterpart.
  * `PHP-server-run` / `PHP-server-serve` — Server lifecycle methods take args via PHP superglobals/configuration, not method params.
  * `PHP-registry-shape` / `PHP-exception-shape` — Constructor/registration shape differs from Python; same callable surface.
  * `PHP-idiom-keyword-positional` — PHP's signature mirrors Python's keyword-only params (after `*`) but as positional params. PHP 8.0+ named args make the call site identical.
  * `PHP-return-typing` — PHP returns void where Python returns the dict reply; same wire effect, the SDK just doesn't expose it.

# Format: `<fully.qualified.symbol>: <rationale>`

signalwire.agent_server.AgentServer.run: PHP-server-run: PHP's AgentServer.serve() handles the same lifecycle as Python's run(event,context,host,port); the host/port/event/context come from PHP request superglobals + AgentServer state, not method args
signalwire.agent_server.AgentServer.register_global_routing_callback: PHP-callback-shape: PHP's callable type-hint erases the (request_data, headers)->?string routing-callback shape to the bare 'callable'; PHP reflection cannot express a typed callable signature. Same callback contract, installed on every agent's routing table verbatim
signalwire.core.agent.prompt.manager.PromptManager.define_contexts: PHP-idiom-fluent-builder: PHP's define_contexts() takes no args and returns a ContextBuilder for fluent chaining; Python takes the contexts dict positionally. Same construction surface
signalwire.core.agent.prompt.manager.PromptManager.prompt_add_section: PHP-idiom-options-trim: PHP's prompt_add_section accepts (title, body, bullets) — the (numbered, numbered_bullets, subsections) variants are appended via the Section builder methods on the returned object
signalwire.core.agent.prompt.manager.PromptManager.prompt_add_subsection: PHP-idiom-options-trim: PHP's prompt_add_subsection accepts (parent_title, title, body) — the bullets variant is set via the returned Section builder
signalwire.core.agent.prompt.manager.PromptManager.prompt_add_to_section: PHP-idiom-options-trim: PHP's prompt_add_to_section accepts (title, body, bullets) — Python's separate (bullet, bullets) split is not needed since PHP's bullets array carries both
signalwire.core.agent.tools.registry.ToolRegistry.define_tool: PHP-idiom-options-trim: PHP's define_tool accepts (name, description, parameters, handler, secure) — fillers/wait_file/webhook_url/required/is_typed_handler/swaig_fields are configured via subsequent builder methods on the returned tool
signalwire.core.agent_base.AgentBase.add_answer_verb: PHP-extra-param: PHP's add_answer_verb accepts (verb, config) so the verb name can be supplied by the caller; Python's addAnswerVerb takes config-only and infers the verb. Same effect, more explicit shape
signalwire.core.agent_base.AgentBase.create_tool_token: PHP-additional-API: PHP's AgentBase exposes create_tool_token as a public helper (paired with validate_tool_token); Python keeps token creation internal to SessionManager
signalwire.core.agent_base.AgentBase.enable_sip_routing: PHP-idiom-options-trim: PHP's enable_sip_routing() takes no args (uses configuration defaults); the (auto_map, path) overrides go through setupSipRouting() instead
signalwire.core.agent_base.AgentBase.on_debug_event: PHP-idiom-args-rename: the reference names the handler param `handler`; PHP's onDebugEvent(callable $callback) names it `callback` (AgentBase.php:1718). Both are a plain callable of the same arity; only the param NAME differs, and the fluent `self` return is PHP's chaining idiom against the reference's callable return.
signalwire.core.agent_base.AgentBase.on_summary: PHP-param-typing: PHP's onSummary(array|string|null $summary, ?array $rawData = null) (AgentBase.php:1697) takes the SAME two positional params as the reference — the divergence is the PARAM TYPE only: the reference types them as the generated PostPromptData / PostPrompt models, PHP as the decoded `array` payload (PHP has no generated post-prompt model class). Same handler contract, same arity, same order.
signalwire.core.agent_base.AgentBase.skill_manager: PHP-idiom-internal: Python's '@property skill_manager' is internal accessor; PHP routes skill-management through public methods (add_skill, list_skills, has_skill) — the manager itself is not part of the public surface
signalwire.core.contexts.Context.add_step: PHP-idiom-keyword-positional: PHP signature mirrors Python params (task/bullets/criteria/functions/valid_steps) but as positional rather than Python's keyword-only after `*`. Same call surface for callers using named args (PHP 8.0+).
signalwire.core.data_map.DataMap.expression: PHP-pattern-typing: PHP doesn't ship a Pattern class — the regex pattern is just a string. Same semantic surface as Python's union<Pattern,string>
signalwire.core.data_map.create_expression_tool: PHP-array-typing: PHP's bare `array` erases the concrete `dict<string,tuple<string,FunctionResult>>` patterns shape (PHP has no tuple type — each entry is [pattern, FunctionResult]); reflection cannot express the element type. Same pattern-map contract, applied verbatim to the built DataMap
signalwire.core.function_result.FunctionResult.add_action: PHP-callback-shape: PHP's add_action takes a single (action) array containing both name and data; Python takes them as separate positional params. Same effect
signalwire.core.function_result.FunctionResult.toggle_functions: PHP-param-shape: PHP's toggleFunctions takes an ergonomic map `array<string,bool>` (function name => active flag) and reshapes it internally to the wire list `[{function, active}, …]`; Python's toggle_functions takes that wire-shape `list<dict<string,any>>` directly. Same toggle_functions wire output (byte-identical), different input ergonomics — the port deliberately accepts the map form (tightening to the reference's list<dict> would change what the PHP method accepts at runtime). Not a loose `any`: the PHPDoc records the concrete `array<string,bool>`.
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_function_include: PHP-idiom-options-collapse: PHP's add_function_include takes a single (include) array carrying url/functions/meta_data; Python expands them as separate positional params
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_post_prompt_llm_params: PHP-idiom-kwargs: PHP has no **kwargs syntax — Python's variadic kwargs translate to PHP's 'array $params' / 'array $kwargs' positional argument
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_prompt_llm_params: PHP-idiom-kwargs: PHP has no **kwargs syntax — Python's variadic kwargs translate to PHP's 'array $params' / 'array $kwargs' positional argument
signalwire.core.mixins.auth_mixin.AuthMixin.get_basic_auth_credentials: PHP-idiom-method-split: PHP exposes get_basic_auth_credentials() (no include_source param) and a separate get_basic_auth_credentials_with_source() (already documented in PORT_ADDITIONS as PHP-extra surface)
signalwire.core.mixins.prompt_mixin.PromptMixin.define_contexts: PHP-idiom-fluent-builder: PHP's define_contexts() takes no args and returns a ContextBuilder for fluent chaining; Python takes the contexts dict positionally. Same construction surface
signalwire.core.mixins.prompt_mixin.PromptMixin.prompt_add_section: PHP-idiom-options-trim: PHP's prompt_add_section accepts (title, body, bullets) — the (numbered, numbered_bullets, subsections) variants are appended via the Section builder methods on the returned object
signalwire.core.mixins.prompt_mixin.PromptMixin.prompt_add_subsection: PHP-idiom-options-trim: PHP's prompt_add_subsection accepts (parent_title, title, body) — the bullets variant is set via the returned Section builder
signalwire.core.mixins.prompt_mixin.PromptMixin.prompt_add_to_section: PHP-idiom-options-trim: PHP's prompt_add_to_section accepts (title, body, bullets) — Python's separate (bullet, bullets) split is not needed since PHP's bullets array carries both
signalwire.core.mixins.tool_mixin.ToolMixin.define_tool: PHP-idiom-options-trim: PHP's define_tool accepts (name, description, parameters, handler, secure) — fillers/wait_file/webhook_url/required/is_typed_handler/swaig_fields are configured via subsequent builder methods on the returned tool
signalwire.core.mixins.tool_mixin.ToolMixin.define_tools: PHP-idiom-options-trim: PHP's define_tool accepts (name, description, parameters, handler, secure) — fillers/wait_file/webhook_url/required/is_typed_handler/swaig_fields are configured via subsequent builder methods on the returned tool
signalwire.core.mixins.web_mixin.WebMixin.on_swml_request: PHP-idiom-args-trim: PHP's on_swml_request hook takes (request_data, callback_path); the Python 'request' object is delivered via the AgentServer's PSR-7 layer and not part of the user-facing hook signature
signalwire.core.mixins.web_mixin.WebMixin.register_routing_callback: PHP-idiom-args-rename: PHP's registerRoutingCallback(callable $callback, string $path = '/sip') (SWML/Service.php:369) takes a real `callable`, same arity and same order as the reference; the divergence is the first param's NAME only — the reference calls it `callback_fn`, PHP calls it `callback`. Same dispatch contract.
signalwire.core.mixins.web_mixin.WebMixin.run: PHP-server-run: PHP's WebMixin.run() / serve() handle the same lifecycle as Python's run(); arguments come from PHP request superglobals + AgentServer state
signalwire.core.mixins.web_mixin.WebMixin.serve: PHP-server-serve: PHP's serve() handles the same TLS / host / port wiring via AgentServer configuration getters/setters; not exposed as method args
signalwire.core.mixins.web_mixin.WebMixin.set_dynamic_config_callback: PHP-callable-typing: PHP's reflection emits a typed Callable class wrapper; Python uses canonical callable<list<dict,dict,dict,AgentBase>,void>. Same call contract.
signalwire.core.skill_base.SkillBase.define_tool: PHP-idiom-options-trim: PHP's define_tool accepts (name, description, parameters, handler) — Python's **kwargs bag (which forwards fillers/wait_file/webhook_url/required/... to agent.define_tool) is expressed as the explicit core params plus the skill's swaig_fields merge; the remaining tool options are configured via the returned tool builder (same pattern as ToolRegistry/ToolMixin.define_tool).
signalwire.core.skill_manager.SkillManager.load_skill: PHP-registry-shape: PHP's loadSkill(string $skillName, ?string $skillClass = null, ?array $params = null) (Skills/SkillManager.php:40) matches the reference's param NAMES and ORDER exactly. The one irreducible divergence is `skillClass`'s TYPE: the reference takes a first-class `SkillBase` class object, PHP takes a class-string<SkillBase> FQCN (`?string`) — PHP has no runtime class-object handle, so an autoloadable FQCN is the only "class you can instantiate" (`new $className(...)`). Same class-string idiom as SkillRegistry.register_skill / get_skill_class. Same load-skill resolution surface.
signalwire.core.swml_handler.AIVerbHandler.build_config: PHP-idiom-kwargs: PHP has no **kwargs and an override must keep the abstract's signature, so build_config(prompt_text, prompt_pom, contexts, post_prompt, post_prompt_url, swaig, **kwargs) collapses to build_config(array $kwargs) — the same keys (prompt_text/prompt_pom/contexts/post_prompt/post_prompt_url/swaig + extras) are read out of the array, producing byte-identical verb config. Matches TS's buildConfig(opts) single-options shape.
signalwire.core.swml_handler.AIVerbHandler.validate_config: PHP-additional-API: the reference records validate_config only on the SWMLVerbHandler base (the concrete AIVerbHandler override is folded into the base in Python's enumeration); PHP declares the concrete override on AIVerbHandler as PSR-4/LSP require, so the enumerator sees an extra node. Same (config)->[bool, errors] contract as the base.
signalwire.core.swml_service.SWMLService.define_tool: PHP-additional-API: PHP flattens Python's composed ToolRegistry onto Service (SWMLService), so 'define_tool' is a real public method on SWMLService (surface-folded onto the reference ToolRegistry.define_tool via the enumerator's ToolRegistry projection). Reflection therefore emits it on SWMLService too; the reference SWMLService has no such member — same callable surface, different (flattened) filing. Surface-dead/signature-live twin of the folded SWMLService->ToolRegistry composition-collapse.
signalwire.core.swml_service.SWMLService.get_basic_auth_credentials: PHP-idiom-method-split: PHP exposes get_basic_auth_credentials() (no include_source param) and a separate get_basic_auth_credentials_with_source() (already documented in PORT_ADDITIONS as PHP-extra surface)
signalwire.core.swml_service.SWMLService.get_document: PHP-builder-api: PHP's get_document() returns a Document builder object; Python returns a serialized dict<string,any>. Document::toArray() yields the same dict shape
signalwire.core.swml_service.SWMLService.get_function: PHP-additional-API: PHP's SWMLService exposes 'get_function' as a public method (Python keeps the same functionality but via internal modules); same callable surface, different visibility
signalwire.core.swml_service.SWMLService.handle_request: PHP-param-shape: PHP's handleRequest signature is (string $method, string $path, array $headers, ?string $body = null) (SWML/Service.php:903) — same arity and order as the reference; the ONLY gate-visible divergence is param[3] `body`, which takes the RAW HTTP request body as a string (json_decode'd internally with a 1MB size guard) rather than Python's already-parsed optional<dict<string,any>>. Python couples request handling to the FastAPI route function, which pre-parses the JSON body; PHP has no bundled web framework, so its native entry point receives the raw wire body string and parses it itself. Same request→[status,headers,body] dispatch behaviour; tightening `body` to the reference dict would change what the PHP entry point accepts at runtime. (The `headers` param IS reconciled to the reference dict<string,string> via the PHPDoc-backed PARAM_TYPE_REMAP.) Param[2] is additionally spelled `path` where the reference spells it `url` — same value (the request path); the signature differ does not compare param NAMES, so this is invisible to DRIFT either way and is recorded here for accuracy, not as the reason for the entry.
signalwire.core.swml_service.SWMLService.has_function: PHP-additional-API: PHP's SWMLService exposes 'has_function' as a public method (Python keeps the same functionality but via internal modules); same callable surface, different visibility
signalwire.core.swml_service.SWMLService.register_routing_callback: PHP-idiom-args-rename: PHP's registerRoutingCallback(callable $callback, string $path = '/sip') (SWML/Service.php:369) takes a real `callable`, same arity and same order as the reference; the divergence is the first param's NAME only — the reference calls it `callback_fn`, PHP calls it `callback`. Same dispatch contract.
signalwire.core.swml_service.SWMLService.register_swaig_function: PHP-additional-API: PHP flattens Python's composed ToolRegistry onto Service (SWMLService), so 'register_swaig_function' is a real public method on SWMLService (surface-folded onto the reference ToolRegistry.register_swaig_function via the enumerator's ToolRegistry projection). Reflection therefore emits it on SWMLService too; the reference SWMLService has no such member — same callable surface, different (flattened) filing. Surface-dead/signature-live twin of the folded SWMLService->ToolRegistry composition-collapse.
signalwire.core.swml_service.SWMLService.remove_function: PHP-additional-API: PHP's SWMLService exposes 'remove_function' as a public method (Python keeps the same functionality but via internal modules); same callable surface, different visibility
signalwire.core.swml_service.SWMLService.serve: PHP-server-serve: PHP's serve() handles the same TLS / host / port wiring via AgentServer configuration getters/setters; not exposed as method args
signalwire.pom.pom.PromptObjectModel.add_section: PHP-idiom-kwargs: PHP collapses Python's keyword-only params (body, bullets, numbered, numberedBullets) into a single `array $params` positional arg; PHP 8.0+ named args make the call site near-identical
signalwire.pom.pom.Section.add_subsection: PHP-idiom-kwargs: PHP collapses Python's keyword-only params (body, bullets, numbered, numberedBullets) into a single `array $params` positional arg; PHP 8.0+ named args make the call site near-identical
signalwire.prefabs.info_gatherer.InfoGathererAgent.on_swml_request: PHP-idiom-args-trim: PHP's on_swml_request override takes (request_data, callback_path) matching the base SWMLService hook; the Python 'request' object is delivered via the AgentServer's PSR-7 layer and not part of the user-facing hook signature — see web_mixin.WebMixin.on_swml_request
signalwire.prefabs.info_gatherer.InfoGathererAgent.set_question_callback: PHP-callback-shape: PHP's callable type-hint erases the (query_params, body_params, headers)->list<question> shape to the bare 'callable'; PHP reflection cannot express a typed callable signature. Same three-arg question-callback contract, invoked verbatim in on_swml_request
signalwire.relay.call.StandaloneCollectAction.start_input_timers: PHP-return-typing: PHP returns void (the SDK does not surface the wire response); Python returns the dict reply. Same wire effect — see CollectAction.start_input_timers
signalwire.relay.call.Action.result: PHP-return-typing: PHP's Action::getResult() (Relay/Action.php:130) IS the reference's `@property result` (canonicalised getResult->result by the enumerator) and takes the same zero params; the divergence is the RETURN TYPE — the reference declares optional<RelayEvent>, PHP declares no return type (`@return mixed`) because the stored result is the raw decoded reply, not a wrapped RelayEvent. Same accessor, looser static type.
signalwire.relay.call.Action.wait: PHP-event-typing: PHP's Action.wait() returns optional<Event>; Python returns RelayEvent (alias subclass). Both wrap the same underlying event payload
signalwire.relay.call.Call.ai: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.ai_hold: PHP-idiom-noargs: PHP's Call.ai_hold() takes no args; defaults match Python's (timeout=None, prompt=None)
signalwire.relay.call.Call.ai_message: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.ai_unhold: PHP-idiom-noargs: PHP's Call.ai_unhold() takes no args; defaults match Python's (prompt=None)
signalwire.relay.call.Call.amazon_bedrock: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.answer: PHP-idiom-noargs: PHP's answer(): array (Relay/Call.php:503) takes no args — the reference's trailing `**kwargs` catch-all carries no documented field for calling.answer, so PHP omits the pass-through bag. Same wire call (calling.answer with no params) and the same dict/array reply.
signalwire.relay.call.Call.bind_digit: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.collect: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.connect: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.detect: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.detect_answering_machine: PHP-idiom-options-collapse: PHP's detectAnsweringMachine(array $opts) carries Python's keyword-only AMD tuning fields (initial_timeout/end_silence_timeout/machine_voice_threshold/machine_words_threshold/detect_interruptions/detect_message_end/timeout/on_completed) in one array; same only-provided-keys wire shape ({type:"machine", params:{…}} + sibling timeout)
signalwire.relay.call.Call.detect_digit: PHP-idiom-options-collapse: PHP's detectDigit(array $opts) carries Python's keyword-only digits/timeout/on_completed in one array; same wire shape ({type:"digit", params:{digits?}} + sibling timeout)
signalwire.relay.call.Call.detect_fax: PHP-idiom-options-collapse: PHP's detectFax(array $opts) carries Python's keyword-only tone/timeout/on_completed in one array; same wire shape ({type:"fax", params:{tone?}} + sibling timeout)
signalwire.relay.call.Call.echo: PHP-idiom-noargs: PHP's Call.echo() runs the echo verb with default params; the Python (timeout, status_url) overrides go via the EchoBuilder
signalwire.relay.call.Call.join_conference: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.join_room: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.leave_conference: PHP-idiom-noargs: PHP's Call.leave_conference() leaves the current conference; the Python 'conference_id' argument is unused (PHP uses the call's currently-joined conference)
signalwire.relay.call.Call.leave_room: PHP-idiom-noargs: PHP's leaveRoom(): array (Relay/Call.php:675) takes no args — the reference's trailing `**kwargs` catch-all carries no documented field for calling.room.leave, so PHP omits the pass-through bag. Same wire call and the same dict/array reply.
signalwire.relay.call.Call.live_transcribe: PHP-idiom-kwargs: PHP's liveTranscribe(array $action, array $kwargs = []) (Relay/Call.php:640) keeps the reference's `action` param verbatim and adds a trailing `$kwargs` array because PHP has no `**kwargs` syntax; the two are merged (`['action' => $action] + $kwargs`) into the same wire params. The `action` type is the bare `array` PHP reflection can express against the reference's dict<string,any>.
signalwire.relay.call.Call.live_translate: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.on: PHP-callback-shape: PHP's Call.on(event_type, cb) uses 'cb' for the handler; Python uses 'handler'. Functional contract identical
signalwire.relay.call.Call.pay: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.play: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.play_and_collect: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.play_audio: PHP-idiom-options-collapse: PHP's playAudio(url, array $opts) carries Python's keyword-only volume/on_completed in one array; same wire shape ([{type:"audio", params:{url}}] + sibling volume)
signalwire.relay.call.Call.play_ringtone: PHP-idiom-options-collapse: PHP's playRingtone(name, array $opts) carries Python's keyword-only duration/volume/on_completed in one array; same wire shape ([{type:"ringtone", params:{name, duration?}}] + sibling volume)
signalwire.relay.call.Call.play_silence: PHP-param-typing: PHP's playSilence(int|float $duration, ?callable $onCompleted = null) (Relay/Call.php:876) exposes the SAME two params (duration + keyword-only on_completed) as the reference. Two type encodings differ: `duration` is `int|float` where the reference declares `float` (PHP would reject a bare int under a strict `float` hint), and the `on_completed` callable's element type is un-annotatable in PHP reflection (callable<list<any>,any> vs the reference's callable<list<RelayEvent>,any>). Same wire shape ([{type:"silence", params:{duration}}]).
signalwire.relay.call.Call.play_tts: PHP-idiom-options-collapse: PHP's playTts(text, array $opts) carries Python's keyword-only language/gender/voice/volume/on_completed in one array; same wire shape ([{type:"tts", params:{text, language?, gender?, voice?}}] + sibling volume)
signalwire.relay.call.Call.prompt_audio: PHP-idiom-options-collapse: PHP's promptAudio(url, collect, array $opts) carries Python's keyword-only volume/on_completed in one array; same wire shape (play_and_collect [{type:"audio", params:{url}}] + given collect + sibling volume)
signalwire.relay.call.Call.prompt_tts: PHP-idiom-options-collapse: PHP's promptTts(text, collect, array $opts) carries Python's keyword-only language/gender/voice/volume/on_completed in one array; same wire shape (play_and_collect [{type:"tts", params:{text, language?, gender?, voice?}}] + given collect + sibling volume)
signalwire.relay.call.Call.queue_enter: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.queue_leave: PHP-idiom-keyword-positional: PHP signature mirrors Python params (queue_name, control_id, queue_id, status_url, kwargs) but as positional rather than Python's keyword-only after `*`. Same call surface for callers using named args (PHP 8.0+).
signalwire.relay.call.Call.receive_fax: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.record: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.refer: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.send_digits: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.send_fax: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.stream: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.tap: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.transcribe: PHP-idiom-options-collapse: PHP signature collapses Python's positional params into a single 'array $options/$opts' argument; same fields available, different surface shape
signalwire.relay.call.Call.transfer: PHP-idiom-params-collapse: PHP signature collapses Python's positional fields into a single 'array $params' argument; same fields available
signalwire.relay.call.Call.user_event: PHP-idiom-kwargs: PHP's userEvent(?string $event = null, array $kwargs = []) (Relay/Call.php:721) keeps the reference's keyword-only `event` param verbatim and adds a trailing `$kwargs` array because PHP has no `**kwargs` syntax; both are folded into the same wire params. Only the reflection KIND of the trailing bag (positional array vs var_keyword) differs.
signalwire.relay.call.CollectAction.start_input_timers: PHP-return-typing: PHP returns void (the SDK does not surface the wire response); Python returns the dict reply. Same wire effect.
signalwire.relay.call.CollectAction.pause: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.play_and_collect.pause).
signalwire.relay.call.CollectAction.resume: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.play_and_collect.resume).
signalwire.relay.call.CollectAction.volume: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.play_and_collect.volume).
signalwire.relay.call.PlayAction.pause: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.play.pause).
signalwire.relay.call.PlayAction.resume: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.play.resume).
signalwire.relay.call.PlayAction.volume: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.play.volume).
signalwire.relay.call.RecordAction.pause: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.record.pause).
signalwire.relay.call.RecordAction.resume: PHP-return-typing: PHP returns void; Python returns the dict reply. Same wire effect (calling.record.resume).
signalwire.relay.client.RelayClient.dial: PHP-idiom-options-trim: PHP's RelayClient.dial takes (devices, opts); Python expands tag/max_duration/dial_timeout as KEYWORD-only params (oracle kind=keyword), now nested under opts
signalwire.relay.client.RelayClient.send_message: PHP-idiom-options-collapse: PHP's RelayClient.send_message takes (params) array carrying to_number/from_number/context/body/media/tags/region/on_completed; Python expands them as KEYWORD-only params (oracle kind=keyword)
signalwire.relay.message.Message.on: PHP-idiom-args-rename: PHP's on(callable $cb): self (Relay/Message.php:198) takes the same single callable as the reference; the divergences are the param NAME (`cb` vs the reference's `handler`), the un-annotatable callable element type (callable<list<any>,any> vs callable<list<RelayEvent>,any> — PHP reflection cannot express a typed callable), and the fluent `self` return against the reference's void. Same registration contract.
signalwire.relay.message.Message.wait: PHP-param-typing: PHP's wait(int|float|null $timeout = null) (Relay/Message.php:179) accepts fractional-second timeouts exactly like the reference's optional<float>; the divergence is that PHP's union additionally admits `int` (a bare `float` hint would reject an int literal under strict_types) and the method declares no return type where the reference declares RelayEvent. Same timeout semantics.
signalwire.skills.registry.SkillRegistry.register_skill: PHP-registry-shape: PHP's SkillRegistry.register_skill takes (name, class_name) — Python takes the skill class itself; PHP uses string class names (autoload-compatible) for runtime registration
signalwire.skills.registry.SkillRegistry.get_skill_class: PHP-registry-shape: PHP's SkillRegistry.get_skill_class returns a class-string<SkillBase> (string) rather than Python's SkillBase class object; PHP has no first-class runtime type handle — an autoloadable FQCN is the idiomatic "class you can instantiate" (same as register_skill's class-string input, and getFactory's class-string return). `new $className(...)` instantiates it. Same lookup effect.
signalwire.register_skill: PHP-registry-shape: the facade SignalWire::register_skill takes a class-string<SkillBase> (string) rather than the Python skill-class object; PHP uses string class names (autoload-compatible) for runtime registration, deriving the registration name from the class — same registration effect. See skills.registry.SkillRegistry.register_skill

# livewire — reference signature oracle (griffe) does not enumerate the module (L12)

signalwire.relay.call.Call.clear_digit_bindings: PHP-idiom-kwargs: PHP has no keyword-only params or **kwargs; Python's clear_digit_bindings(*, realm=None, **kwargs) collapses to clearDigitBindings(?string $realm = null, array $kwargs = []) — realm IS ported (filters bindings by realm, emitted verbatim into the wire params) and $kwargs forwards extra params. Same wire call; only the reflection kind (positional vs keyword/var_keyword) differs, reconciled like every other php kwargs method.