# PORT_OMISSIONS — Python symbols the PHP SDK does not implement

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


Every symbol listed here is a public class, method or function present in the Python reference (`porting-sdk/python_surface.json`) that this PHP port deliberately does not expose. Each entry records a one-line rationale; the Phase 13 surface audit in CI will reject any Python symbol missing from the PHP SDK that is also missing from this file.

As of phase 4 cleanup, every `not_yet_implemented:` entry has been closed. New entries should carry an intentional-divergence rationale (e.g. "Python's helper is internal", "PHP composes the same behavior via X", etc.).


# Format: `<fully.qualified.symbol>: <rationale>`
# Regenerate with `python3 scripts/generate_exemptions.py` after
# a surface change.

signalwire.RestClient: impossible: Python module-level free function; PHP has no module-level free functions (PSR-4 file-per-class), so it is hosted as the static method SignalWire::RestClient (SignalWire.php), surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
signalwire.add_skill_directory: impossible: Python module-level free function; PHP has no module-level free functions (PSR-4 file-per-class), so it is hosted as the static method SignalWire::add_skill_directory (SignalWire.php), surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
signalwire.agents.bedrock.BedrockAgent: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.agents.bedrock.BedrockAgent.__init__: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.agents.bedrock.BedrockAgent.__repr__: impossible: Python __repr__ object-protocol member; PHP has Stringable/__toString but the reference records __repr__ as a Python-protocol member that TS also omits (no stringification-protocol member enumerated on the surface)
signalwire.agents.bedrock.BedrockAgent.set_inference_params: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.agents.bedrock.BedrockAgent.set_llm_model: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.agents.bedrock.BedrockAgent.set_llm_temperature: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.agents.bedrock.BedrockAgent.set_post_prompt_llm_params: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.agents.bedrock.BedrockAgent.set_prompt_llm_params: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.agents.bedrock.BedrockAgent.set_voice: AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.
signalwire.core.agent.prompt.manager.PromptManager: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.define_contexts: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.get_contexts: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.get_post_prompt: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.get_prompt: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.get_raw_prompt: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.prompt_add_section: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.prompt_add_subsection: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.prompt_add_to_section: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.prompt_has_section: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.set_post_prompt: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.set_prompt_pom: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.prompt.manager.PromptManager.set_prompt_text: Python's PromptManager helper is folded into AgentBase's prompt API in PHP (setPromptText / promptAddSection / etc).
signalwire.core.agent.tools.decorator.ToolDecorator: impossible: Python @tool class/instance decorator API relies on the decorator protocol; PHP has no method-decorator language feature — tools register via Service::define_tool(name, description, parameters, handler) directly, exactly as TS registers via defineTools()/the tool builder (TS also omits this as impossible)
signalwire.core.agent.tools.decorator.ToolDecorator.create_class_decorator: impossible: Python @tool class/instance decorator API relies on the decorator protocol; PHP has no method-decorator language feature — tools register via Service::define_tool directly (TS also omits this as impossible)
signalwire.core.agent.tools.decorator.ToolDecorator.create_instance_decorator: impossible: Python @tool class/instance decorator API relies on the decorator protocol; PHP has no method-decorator language feature — tools register via Service::define_tool directly (TS also omits this as impossible)
signalwire.core.agent.tools.registry.ToolRegistry: Python function-decorator + ToolRegistry mechanism; PHP registers tools via `Service::define_tool(name, description, parameters, handler)` directly with no decorator layer.
signalwire.core.agent.tools.registry.ToolRegistry.register_class_decorated_tools: impossible: registers @tool-decorated class methods discovered via the Python decorator protocol; PHP has no method-decorator language feature to discover, so there is nothing to register (TS also omits this as impossible)
signalwire.core.agent_base.AgentBase.auto_map_sip_usernames: Python convenience that auto-registers all public methods as SIP usernames; PHP's strict-typed AgentBase requires `registerSipUsername` calls for each binding (safer + explicit).
signalwire.core.agent_base.AgentBase.get_full_url: Python AgentBase exposes the full-URL string for self-referencing webhooks; PHP exposes host/port/route accessors so users assemble the URL as needed (Service::getFullUrl is on the parent).
signalwire.core.agent_base.AgentBase.get_name: Python AgentBase exposes a `get_name` accessor; PHP delegates to Service::getName on the parent class — same surface, single implementation site.
signalwire.core.auth_handler.AuthHandler.flask_decorator: impossible: produces a Flask view decorator; Flask is a Python web framework with no PHP equivalent — the capability is delivered by a PHP-native auth middleware on AuthHandler (to-implement, recorded as a PORT_ADDITION like TS's AuthHandler.middleware/expressMiddleware). TS also omits this method as impossible.
signalwire.core.auth_handler.AuthHandler.get_fastapi_dependency: impossible: produces a FastAPI Depends() dependency; FastAPI is a Python web framework with no PHP equivalent — the capability is delivered by a PHP-native auth middleware on AuthHandler (to-implement, recorded as a PORT_ADDITION like TS's AuthHandler.middleware). TS also omits this method as impossible.
signalwire.core.contexts.ContextBuilder.__init__: PHP's ContextBuilder uses zero-arg construction (matching Python's init); the constructor signature is implicit. The diff-tool flags this because PHP's enumerator emits the constructor under `addContext` instead.
signalwire.core.contexts.create_simple_context: impossible: Python module-level free function; PHP has no autoloadable module-level free functions (PSR-4 file-per-class), so it is hosted as the static factory Context::createSimpleContext (Contexts/ContextBuilder.php) returning a standalone Context, surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
signalwire.core.data_map.create_expression_tool: impossible: Python module-level free function; PHP has no module-level free functions (PSR-4 file-per-class), so it is hosted as the static factory DataMap::createExpressionTool (DataMap/DataMap.php) returning a configured DataMap, surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
signalwire.core.data_map.create_simple_api_tool: impossible: Python module-level free function; PHP has no module-level free functions (PSR-4 file-per-class), so it is hosted as the static factory DataMap::createSimpleApiTool (DataMap/DataMap.php) returning a configured DataMap, surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
signalwire.core.mixins.tool_mixin.ToolMixin.tool: impossible: Python @tool class/instance decorator relies on the decorator protocol; PHP has no method-decorator language feature — tools register via Service::define_tool directly, exactly as TS registers via defineTools()/the tool builder (TS also omits this as impossible)
signalwire.core.mixins.web_mixin.WebMixin.get_app: impossible: returns the Python FastAPI/Flask app object; PHP uses no web framework, so there is no framework app handle to return — PHP serves via its native Service::handleRequest()/run() (the framework-native equivalent, present). TS handles the same method as framework-bound (ships getApp() returning the Hono app as a recorded addition).
signalwire.core.security.webhook_middleware.make_webhook_validation_dependency: impossible: produces a FastAPI Depends() dependency; FastAPI is a Python web framework with no PHP equivalent — PHP ships the equivalent callable WebhookMiddleware class (recorded in PORT_ADDITIONS.md), wrapping the same signature-validation core. TS also omits this method as impossible (ships a Hono middleware instead).
signalwire.core.skill_base.SkillBase.get_skill_data: Python's SkillBase exposes plugin-discovery and async helpers not applicable to PHP; PHP's SkillBase is a leaner abstract class with the same public surface (getName/getDescription/setup/registerTools).
signalwire.core.skill_base.SkillBase.register_tools: Python's SkillBase exposes plugin-discovery and async helpers not applicable to PHP; PHP's SkillBase is a leaner abstract class with the same public surface (getName/getDescription/setup/registerTools).
signalwire.core.skill_base.SkillBase.setup: Python's SkillBase exposes plugin-discovery and async helpers not applicable to PHP; PHP's SkillBase is a leaner abstract class with the same public surface (getName/getDescription/setup/registerTools).
signalwire.core.skill_base.SkillBase.update_skill_data: Python's SkillBase exposes plugin-discovery and async helpers not applicable to PHP; PHP's SkillBase is a leaner abstract class with the same public surface (getName/getDescription/setup/registerTools).
signalwire.core.swaig_function.SWAIGFunction.__call__: impossible: Python callable-object protocol (__call__); PHP objects invoke via the __invoke interface but the reference records __call__ as a Python-protocol member — the same invocation capability is present verbatim as SWAIGFunction::execute. TS also omits this dunder as impossible (SwaigFunction.execute present verbatim).
signalwire.core.swml_builder.SWMLBuilder.__getattr__: impossible: Python dynamic-attribute protocol (__getattr__); PHP intercepts via the __call magic method (auto-vivified verbs) but the reference records __getattr__ as a Python-protocol member with no static member to enumerate — the verb dispatch capability is present. TS also omits this dunder as impossible.
signalwire.core.swml_service.SWMLService.__getattr__: impossible: Python dynamic-attribute protocol (__getattr__); PHP intercepts via the __call magic method (auto-vivified verbs) but the reference records __getattr__ as a Python-protocol member with no static member to enumerate — the verb dispatch capability is present. TS also omits this dunder as impossible.
signalwire.list_skills: impossible: Python module-level free function; PHP has no module-level free functions (PSR-4 file-per-class), so it is hosted as the static method SignalWire::list_skills (SignalWire.php) returning per-skill metadata dicts, surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
signalwire.list_skills_with_params: impossible: Python module-level free function; PHP has no module-level free functions (PSR-4 file-per-class), so it is hosted as the static method SignalWire::list_skills_with_params (SignalWire.php), surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
# signalwire.pom.pom.* (PromptObjectModel + Section) is implemented as
# typed PHP classes under SignalWire\POM\* — no longer omitted.
signalwire.prefabs.concierge.ConciergeAgent.check_availability: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.concierge.ConciergeAgent.get_directions: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.concierge.ConciergeAgent.on_summary: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.faq_bot.FAQBotAgent.on_summary: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.faq_bot.FAQBotAgent.search_faqs: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.info_gatherer.InfoGathererAgent.on_swml_request: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.info_gatherer.InfoGathererAgent.set_question_callback: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.info_gatherer.InfoGathererAgent.start_questions: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.info_gatherer.InfoGathererAgent.submit_answer: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.receptionist.ReceptionistAgent.on_summary: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.survey.SurveyAgent.log_response: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.survey.SurveyAgent.on_summary: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.prefabs.survey.SurveyAgent.validate_response: Python prefab exposes additional internal helpers not needed in PHP's equivalent prefab class (e.g. PromptManager wrappers, auto-tools registration). PHP prefabs implement the same five agent classes with the documented public constructor.
signalwire.register_skill: impossible: Python module-level free function; PHP has no module-level free functions (PSR-4 file-per-class), so it is hosted as the static method SignalWire::register_skill (SignalWire.php), surfaced via FREE_FUNCTION_PROJECTIONS. Mirrors the logging_config / livewire host precedent. See PORT_ADDITIONS.md.
signalwire.relay.call.AIAction.__init__: impossible: PHP's concrete AIAction (src/SignalWire/Relay/Action.php) inherits the base Action::__construct rather than redeclaring it; the surface enumerator records only textually-declared constructors and does not walk inheritance, so no per-subclass __init__ surfaces — a representation artifact go (factory funcs) and TS also do not surface per-subclass
signalwire.relay.call.AIAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.Call.__repr__: impossible: Python __repr__ object-protocol member; PHP has Stringable/__toString but the reference records __repr__ as a Python-protocol member — stringification is surfaced as a recorded addition, not a __repr__-protocol member. TS also omits this dunder as impossible (surfaces Call.to_string).
signalwire.relay.call.Call.pass_: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.Call.wait_for: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.Call.wait_for_ended: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.CollectAction.__init__: impossible: PHP CollectAction inherits base Action::__construct; enumerator records only declared constructors, no per-subclass __init__ (see AIAction.__init__)
signalwire.relay.call.CollectAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.CollectAction.volume: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.DetectAction.__init__: impossible: PHP DetectAction inherits base Action::__construct; inherited-constructor / enumerator artifact (see AIAction.__init__)
signalwire.relay.call.DetectAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.FaxAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.PayAction.__init__: impossible: PHP PayAction inherits base Action::__construct; inherited-constructor / enumerator artifact (see AIAction.__init__)
signalwire.relay.call.PayAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.PlayAction.__init__: impossible: PHP PlayAction inherits base Action::__construct; inherited-constructor / enumerator artifact (see AIAction.__init__)
signalwire.relay.call.PlayAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.RecordAction.__init__: impossible: PHP RecordAction inherits base Action::__construct; inherited-constructor / enumerator artifact (see AIAction.__init__)
signalwire.relay.call.RecordAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.StandaloneCollectAction: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.StandaloneCollectAction.__init__: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.StandaloneCollectAction.start_input_timers: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.StandaloneCollectAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.StreamAction.__init__: impossible: PHP StreamAction inherits base Action::__construct; inherited-constructor / enumerator artifact (see AIAction.__init__)
signalwire.relay.call.StreamAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.TapAction.__init__: impossible: PHP TapAction inherits base Action::__construct; inherited-constructor / enumerator artifact (see AIAction.__init__)
signalwire.relay.call.TapAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.call.TranscribeAction.__init__: impossible: PHP TranscribeAction inherits base Action::__construct; inherited-constructor / enumerator artifact (see AIAction.__init__)
signalwire.relay.call.TranscribeAction.stop: PHP's Call class exposes the equivalent surface; the listed Python method is an internal helper or uses a Python-specific signature (kwargs / coroutines) that has no direct PHP analog.
signalwire.relay.client.RelayClient.__aenter__: impossible: Python async-context-manager protocol (__aenter__); PHP has no context-manager language protocol — connect-on-enter is expressed via RelayClient::connect() (present verbatim). TS also omits this dunder as impossible.
signalwire.relay.client.RelayClient.__aexit__: impossible: Python async-context-manager protocol (__aexit__); PHP has no context-manager language protocol — disconnect-on-exit is expressed via RelayClient::disconnect() (present verbatim). TS also omits this dunder as impossible.
signalwire.relay.client.RelayClient.__del__: impossible: Python finalizer protocol (__del__); PHP has no deterministic destructor surfaced on the reference surface — cleanup is via disconnect() (present verbatim). TS also omits this dunder as impossible.
signalwire.relay.client.RelayClient.relay_protocol: impossible: Python `@property relay_protocol` returns the negotiated RELAY protocol string; PHP exposes the same negotiated value as the public `RelayClient::$protocol` field (Client.php:40, set from the auth response at :263-265) — a bare attribute read, which the surface enumerator (methods-only) does not record. Same data, PHP property idiom.
signalwire.relay.event.parse_event: impossible: Python module-level free function `parse_event(payload)`; PHP has no module-level free functions (PSR-4 file-per-class), so the dispatcher is hosted as the static method `RelayEvent::parseEvent()` (Relay/Event/RelayEvent.php) — same 23 event-type → subclass dispatch, surfaced as the recorded addition signalwire.relay.event.RelayEvent.parse_event. Mirrors the url_validator.validate_url free-function-host precedent.
signalwire.relay.client.RelayError: PHP's RelayClient builder provides the equivalent configuration; Python's `__aenter__` / `__aexit__` / `__del__` are Python-async lifecycle methods with no PHP analog.
signalwire.relay.client.RelayError.__init__: PHP's RelayClient builder provides the equivalent configuration; Python's `__aenter__` / `__aexit__` / `__del__` are Python-async lifecycle methods with no PHP analog.
signalwire.relay.message.Message.__repr__: impossible: Python __repr__ object-protocol member; PHP has Stringable/__toString but the reference records __repr__ as a Python-protocol member — stringification is surfaced as a recorded addition, not a __repr__-protocol member. TS also omits this dunder as impossible (surfaces Message.to_string).
signalwire.rest._pagination.PaginatedIterator: Python pagination iterator; PHP returns raw arrays and users drive pagination via query params on CrudResource::list().
signalwire.rest._pagination.PaginatedIterator.__init__: Python pagination iterator; PHP returns raw arrays and users drive pagination via query params on CrudResource::list().
signalwire.rest._pagination.PaginatedIterator.__iter__: impossible: Python iterator protocol (__iter__); PHP has the Iterator interface but the reference records __iter__ as a Python-protocol member — pagination is delivered via generator/callable iteration, no __iter__-protocol member to enumerate. TS also omits this dunder as impossible (paginate() async generator).
signalwire.rest._pagination.PaginatedIterator.__next__: impossible: Python iterator protocol (__next__); PHP has the Iterator interface but the reference records __next__ as a Python-protocol member — pagination advances via generator/callable iteration, no __next__-protocol member to enumerate. TS also omits this dunder as impossible (paginate() async generator).
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.__init__: PHP ships ApiNinjasTriviaSkill with the equivalent one-liner surface (DataMap-driven; the upstream URL is the documented API Ninjas endpoint).
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.get_instance_key: PHP ships ApiNinjasTriviaSkill with the equivalent one-liner surface (DataMap-driven; the upstream URL is the documented API Ninjas endpoint).
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.get_parameter_schema: PHP ships ApiNinjasTriviaSkill with the equivalent one-liner surface (DataMap-driven; the upstream URL is the documented API Ninjas endpoint).
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.get_tools: PHP ships ApiNinjasTriviaSkill with the equivalent one-liner surface (DataMap-driven; the upstream URL is the documented API Ninjas endpoint).
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.get_hints: PHP ships ClaudeSkillsSkill (SKILL.md loader); the shell-injection execution path is documented as omitted at the file top.
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.get_instance_key: PHP ships ClaudeSkillsSkill (SKILL.md loader); the shell-injection execution path is documented as omitted at the file top.
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.get_parameter_schema: PHP ships ClaudeSkillsSkill (SKILL.md loader); the shell-injection execution path is documented as omitted at the file top.
signalwire.skills.datasphere.skill.DataSphereSkill.cleanup: PHP ships DataSphereSkill with the equivalent one-liner surface; Python exposes additional internal helpers.
signalwire.skills.datasphere.skill.DataSphereSkill.get_hints: PHP ships DataSphereSkill with the equivalent one-liner surface; Python exposes additional internal helpers.
signalwire.skills.datasphere.skill.DataSphereSkill.get_instance_key: PHP ships DataSphereSkill with the equivalent one-liner surface; Python exposes additional internal helpers.
signalwire.skills.datasphere.skill.DataSphereSkill.get_parameter_schema: PHP ships DataSphereSkill with the equivalent one-liner surface; Python exposes additional internal helpers.
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.get_hints: PHP ships DataSphereServerlessSkill with the equivalent one-liner surface; Python exposes additional internal helpers.
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.get_instance_key: PHP ships DataSphereServerlessSkill with the equivalent one-liner surface; Python exposes additional internal helpers.
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.get_parameter_schema: PHP ships DataSphereServerlessSkill with the equivalent one-liner surface; Python exposes additional internal helpers.
signalwire.skills.datetime.skill.DateTimeSkill.get_hints: PHP ships DateTimeSkill (get_current_time + get_current_date).
signalwire.skills.datetime.skill.DateTimeSkill.get_parameter_schema: PHP ships DateTimeSkill (get_current_time + get_current_date).
signalwire.skills.google_maps.skill.GoogleMapsSkill.get_parameter_schema: PHP ships GoogleMapsSkill; Python exposes a separate GoogleMapsClient helper class for low-level HTTP transport that PHP folds into the skill class.
signalwire.skills.info_gatherer.skill.InfoGathererSkill.get_instance_key: PHP ships InfoGathererSkill (stateful start_questions + submit_answer); Python exposes additional state-machine helpers.
signalwire.skills.info_gatherer.skill.InfoGathererSkill.get_parameter_schema: PHP ships InfoGathererSkill (stateful start_questions + submit_answer); Python exposes additional state-machine helpers.
signalwire.skills.joke.skill.JokeSkill.get_hints: PHP ships JokeSkill (DataMap-driven via API Ninjas).
signalwire.skills.joke.skill.JokeSkill.get_parameter_schema: PHP ships JokeSkill (DataMap-driven via API Ninjas).
signalwire.skills.math.skill.MathSkill.get_hints: PHP ships MathSkill (safe-evaluator built on eval-free expression parsing (no `eval`)).
signalwire.skills.math.skill.MathSkill.get_parameter_schema: PHP ships MathSkill (safe-evaluator built on eval-free expression parsing (no `eval`)).
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.cleanup: PHP ships NativeVectorSearchSkill in network-only mode (remote_url required). Python adds local SQLite/pgvector backends and embedding helpers — see PORT_OMISSIONS top entry for the full rationale.
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_global_data: PHP ships NativeVectorSearchSkill in network-only mode (remote_url required). Python adds local SQLite/pgvector backends and embedding helpers — see PORT_OMISSIONS top entry for the full rationale.
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_instance_key: PHP ships NativeVectorSearchSkill in network-only mode (remote_url required). Python adds local SQLite/pgvector backends and embedding helpers — see PORT_OMISSIONS top entry for the full rationale.
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_parameter_schema: PHP ships NativeVectorSearchSkill in network-only mode (remote_url required). Python adds local SQLite/pgvector backends and embedding helpers — see PORT_OMISSIONS top entry for the full rationale.
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_prompt_sections: PHP ships NativeVectorSearchSkill in network-only mode (remote_url required). Python adds local SQLite/pgvector backends and embedding helpers — see PORT_OMISSIONS top entry for the full rationale.
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.__init__: PHP ships PlayBackgroundFileSkill with the equivalent surface.
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.get_instance_key: PHP ships PlayBackgroundFileSkill with the equivalent surface.
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.get_parameter_schema: PHP ships PlayBackgroundFileSkill with the equivalent surface.
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.get_tools: PHP ships PlayBackgroundFileSkill with the equivalent surface.
signalwire.skills.registry.SkillRegistry.__init__: PHP's SkillRegistry mirrors the Python registry but exposes a narrower public surface (register/get/list).
signalwire.skills.registry.SkillRegistry.add_skill_directory: PHP's SkillRegistry mirrors the Python registry but exposes a narrower public surface (register/get/list).
signalwire.skills.registry.SkillRegistry.discover_skills: PHP's SkillRegistry mirrors the Python registry but exposes a narrower public surface (register/get/list).
signalwire.skills.registry.SkillRegistry.get_all_skills_schema: PHP's SkillRegistry mirrors the Python registry but exposes a narrower public surface (register/get/list).
signalwire.skills.registry.SkillRegistry.get_skill_class: PHP's SkillRegistry mirrors the Python registry but exposes a narrower public surface (register/get/list).
signalwire.skills.registry.SkillRegistry.list_all_skill_sources: PHP's SkillRegistry mirrors the Python registry but exposes a narrower public surface (register/get/list).
signalwire.skills.spider.skill.SpiderSkill.__init__: PHP ships SpiderSkill (URL fetch + HTML strip); Python exposes additional internal scraping helpers.
signalwire.skills.spider.skill.SpiderSkill.cleanup: PHP ships SpiderSkill (URL fetch + HTML strip); Python exposes additional internal scraping helpers.
signalwire.skills.spider.skill.SpiderSkill.get_hints: PHP ships SpiderSkill (URL fetch + HTML strip); Python exposes additional internal scraping helpers.
signalwire.skills.spider.skill.SpiderSkill.get_instance_key: PHP ships SpiderSkill (URL fetch + HTML strip); Python exposes additional internal scraping helpers.
signalwire.skills.spider.skill.SpiderSkill.get_parameter_schema: PHP ships SpiderSkill (URL fetch + HTML strip); Python exposes additional internal scraping helpers.
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.get_hints: PHP ships SwmlTransferSkill with a simplified public surface (pattern-matching for transfer destinations).
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.get_instance_key: PHP ships SwmlTransferSkill with a simplified public surface (pattern-matching for transfer destinations).
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.get_parameter_schema: PHP ships SwmlTransferSkill with a simplified public surface (pattern-matching for transfer destinations).
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.get_prompt_sections: PHP ships SwmlTransferSkill with a simplified public surface (pattern-matching for transfer destinations).
signalwire.skills.weather_api.skill.WeatherApiSkill.__init__: PHP ships WeatherApiSkill (DataMap-driven); Python exposes additional response-parsing internal helpers.
signalwire.skills.weather_api.skill.WeatherApiSkill.get_parameter_schema: PHP ships WeatherApiSkill (DataMap-driven); Python exposes additional response-parsing internal helpers.
signalwire.skills.weather_api.skill.WeatherApiSkill.get_tools: PHP ships WeatherApiSkill (DataMap-driven); Python exposes additional response-parsing internal helpers.
signalwire.skills.web_search.skill.WebSearchSkill.get_hints: PHP ships a lighter web-search skill; Python's GoogleSearchScraper helpers are Python-specific (BeautifulSoup-based per-result HTML scrape + Reddit-aware extractor + per-domain quality table).
signalwire.skills.web_search.skill.WebSearchSkill.get_instance_key: PHP ships a lighter web-search skill; Python's GoogleSearchScraper helpers are Python-specific (BeautifulSoup-based per-result HTML scrape + Reddit-aware extractor + per-domain quality table).
signalwire.skills.web_search.skill.WebSearchSkill.get_parameter_schema: PHP ships a lighter web-search skill; Python's GoogleSearchScraper helpers are Python-specific (BeautifulSoup-based per-result HTML scrape + Reddit-aware extractor + per-domain quality table).
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.get_hints: PHP ships WikipediaSearchSkill with the equivalent one-liner surface; Python exposes additional helper methods for response parsing.
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.get_parameter_schema: PHP ships WikipediaSearchSkill with the equivalent one-liner surface; Python exposes additional helper methods for response parsing.
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.search_wiki: PHP ships WikipediaSearchSkill with the equivalent one-liner surface; Python exposes additional helper methods for response parsing.
signalwire.utils.schema_utils.SchemaUtils: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.__init__: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.full_validation_available: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.generate_method_body: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.generate_method_signature: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.get_all_verb_names: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.get_verb_parameters: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.get_verb_properties: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.get_verb_required_properties: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.load_schema: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.validate_document: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaUtils.validate_verb: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaValidationError: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.schema_utils.SchemaValidationError.__init__: Python's schema utils include load/validate helpers used by the SWMLService; PHP does schema loading inline in the Schema class (loads schema.json once at construction).
signalwire.utils.url_validator.validate_url: impossible: PHP (PSR-4) cannot declare a module-level free function; validate_url is hosted as UrlValidator::validateUrl (PORT_ADDITIONS) and URL validation happens at call time via filter_var(FILTER_VALIDATE_URL). Python URL-validation helper used by SWMLService; PHP's Document validates URLs at call time via `filter_var(..., FILTER_VALIDATE_URL)`.
signalwire.core.security.security_utils.filter_sensitive_headers: impossible: implemented as static method SecurityUtils::filterSensitiveHeaders (language idiom); see PORT_ADDITIONS.md PHP (PSR-4, file-per-class) cannot declare a module-level free function; the capability is present as the hosted static method (recorded in PORT_ADDITIONS).
signalwire.core.security.security_utils.redact_url: impossible: implemented as static method SecurityUtils::redactUrl (language idiom); see PORT_ADDITIONS.md PHP (PSR-4, file-per-class) cannot declare a module-level free function; the capability is present as the hosted static method (recorded in PORT_ADDITIONS).
signalwire.core.security.security_utils.is_valid_hostname: impossible: implemented as static method SecurityUtils::isValidHostname (language idiom); see PORT_ADDITIONS.md PHP (PSR-4, file-per-class) cannot declare a module-level free function; the capability is present as the hosted static method (recorded in PORT_ADDITIONS).

# --- LiveWire (LiveKit-agents compat shim) — PHP is not a LiveKit agents SDK language ---
signalwire.ai_chat.client.AIChatClient.__aenter__: impossible: Python's async context-manager entry dunder (`async with AIChatClient(...) as c`). PHP has no context-manager / with-block / RAII-dispose protocol at all — the client is a per-request cURL transport that opens and closes a fresh handle each call, and `close()` is a no-op — so there is no language construct to host an enter hook. Same irreducible language limit as RelayClient's context-manager dunders. Lifecycle is fully covered by the ctor + `close()`; the client's wire behavior is proven by the AI-CHAT wire gate.
signalwire.ai_chat.client.AIChatClient.__aexit__: impossible: Python's async context-manager exit dunder (calls `close()` on block exit). PHP has no context-manager / with-block / RAII-dispose protocol at all — nothing invokes an exit hook — so it is un-expressible. The equivalent cleanup is the explicit `close()` lifecycle member (a no-op here, since each request opens/closes its own cURL handle). Same irreducible language limit as RelayClient's context-manager dunders. See AIChatClient.__aenter__.

<!-- family-folded surface twins (wave-2 allowlist fold) -->
agentbase-family.get_app: impossible: returns the Python FastAPI/Flask app object; PHP uses no web framework, so there is no framework app handle to return — PHP serves via its native Service::handleRequest()/run(). Family-folded twin of the ToolMixin/WebMixin unfolded entry (TS ships getApp() as a framework-bound addition).
agentbase-family.tool: impossible: Python @tool class/instance decorator relies on the decorator protocol; PHP has no method-decorator language feature — tools register via Service::define_tool directly (TS also omits this as impossible). Family-folded twin of the ToolMixin.tool unfolded entry.
agentbase-family.skill_manager: impossible: PHP AgentBase keeps its SkillManager composition PROTECTED (getSkillManager() is not public) — there is NO public member exposing it under any name; the public skill surface is add_skill/remove_skill/has_skill/list_skills. Not a rename (no public accessor exists to rename).
signalwire.agent_server.AgentServer.agents: impossible: the reference exposes BOTH a get_agents() METHOD and a raw `agents` dict attribute; PHP's single public getAgents() already matches the reference get_agents() by name, and PHP exposes NO separate public member for the raw dict — renaming getAgents->agents would orphan the reference get_agents(). No distinct public member to rename.
signalwire.core.skill_manager.SkillManager.loaded_skills: impossible: the reference exposes BOTH list_loaded_skills() (list<str>, which PHP's listLoadedSkills() already matches by name) and a raw `loaded_skills` dict attribute; PHP keeps $loadedSkills PROTECTED and exposes only the keys list + per-key get_skill()/has_skill() — NO public member returns the raw dict, so there is nothing to rename onto `loaded_skills`.
signalwire.core.swml_service.SWMLService.security: impossible: PHP SWMLService composes its security config internally and exposes NO public member for it (securityHeaders() is protected and returns headers, not the SecurityConfig). No public accessor exists to rename onto `security`.
signalwire.core.swml_service.SWMLService.verb_registry: impossible: PHP SWMLService keeps $verbRegistry PROTECTED and exposes NO public member returning it — the public verb surface is add_verb/add_verb_to_section/register_verb_handler. No public accessor exists to rename onto `verb_registry`.
