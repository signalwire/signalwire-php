# PORT_ADDITIONS — PHP-only public symbols with no Python equivalent

Symbols here exist in the PHP SDK but have no matching entry in the Python reference. These fall into three buckets:
  1. PHP-idiomatic accessors (getX(), setX(), explicit getters).
  2. Method-name variants where PHP's idiom differs from Python.
  3. Refactors where PHP merged Python's split classes (`*Namespace` vs `*Resource`).

Every entry must carry a rationale. Reviewers use this file to catch accidental additions.


# Format: `<fully.qualified.symbol>: <rationale>`
# Regenerate with `python3 scripts/generate_exemptions.py` after
# a surface change.

signalwire.SignalWire: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.SignalWire.get_logger: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.agent_server.AgentServer.get_host: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.get_port: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.get_sip_username_mapping: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.handle_request: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.is_sip_routing_enabled: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.agent_server.AgentServer.serve: PHP idiomatic accessor / lifecycle hook on AgentServer.
signalwire.cli.simulation.mock_env.Adapter: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.cli.simulation.mock_env.Adapter.detect: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_azure: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_cgi: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_gcf: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.handle_lambda: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.cli.simulation.mock_env.Adapter.serve: PHP-specific platform-mode-detection adapter (lambda / gcf / azure / cgi); Python uses the broader simulation/mock_env ServerlessSimulator class.
signalwire.core.agent_base.AgentBase.add_function_include: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.add_hint: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.add_hints: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.add_internal_filler: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.add_language: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.add_pattern_hint: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.add_pronunciation: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.add_skill: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.build_ai_verb: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.clone_for_request: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.contexts: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.define_contexts: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.enable_debug_events: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.get_prompt: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.has_skill: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.list_skills: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.list_tool_names: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.manual_set_proxy_url: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.prompt_add_section: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.prompt_add_subsection: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.prompt_add_to_section: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.prompt_has_section: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.remove_skill: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.render_swml: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.reset_contexts: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_dynamic_config_callback: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_function_includes: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_global_data: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_internal_fillers: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_languages: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_native_functions: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_param: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_params: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_post_prompt: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_post_prompt_llm_params: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_prompt_llm_params: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_prompt_text: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.set_pronunciations: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
signalwire.core.agent_base.AgentBase.update_global_data: PHP idiomatic getter / explicit accessor for an internal AgentBase field; Python users access the same data via `agent.<attr>` direct attribute access.
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
signalwire.core.skill_base.SkillBase.get_required_env_vars: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase.
signalwire.core.skill_base.SkillBase.get_version: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase.
signalwire.core.skill_base.SkillBase.supports_multiple_instances: PHP idiomatic accessor / lifecycle hook on the abstract SkillBase.
signalwire.core.skill_manager.SkillManager.list_skills: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
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
signalwire.core.swml_service.SWMLService.__call: PHP magic-method dispatcher that routes auto-vivified verb names (answer, hangup, play, etc) to Document::addVerb. Python uses `__getattr__` instead.
signalwire.core.swml_service.SWMLService.define_tool: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.define_tools: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.dispatch_from_globals: PHP CGI-mode helper that reads from `$_SERVER` / `$_REQUEST` globals; Python's equivalent is the WSGI/ASGI adapter shipped with FastAPI.
signalwire.core.swml_service.SWMLService.get_full_url: PHP getter for the host:port:route URL; Python computes this internally but doesn't expose a separate accessor on SWMLService.
signalwire.core.swml_service.SWMLService.get_host: PHP getter exposing the bind host; Python uses the underlying uvicorn config directly.
signalwire.core.swml_service.SWMLService.get_name: PHP getter for the service name; Python users access via `service.name` attribute directly.
signalwire.core.swml_service.SWMLService.get_port: PHP getter for the bind port; Python uses the underlying uvicorn config directly.
signalwire.core.swml_service.SWMLService.get_proxy_url_base: PHP getter for the SWML proxy URL; Python users access via the `SWML_PROXY_URL_BASE` env var directly.
signalwire.core.swml_service.SWMLService.get_route: PHP getter for the bind route; Python users access via `service.route` attribute directly.
signalwire.core.swml_service.SWMLService.get_tool_names: PHP getter for the registered tool names list; Python users iterate the underlying `_tool_registry` dict.
signalwire.core.swml_service.SWMLService.get_tools: PHP getter for the full tool registry; Python exposes the same data via the registry's iter API.
signalwire.core.swml_service.SWMLService.handle_request: PHP entry-point for HTTP request dispatch; Python's equivalent is the FastAPI route function the framework wires up.
signalwire.core.swml_service.SWMLService.on_function_call: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.register_swaig_function: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.core.swml_service.SWMLService.render: PHP method exposing the canonical `render()` entry point — Python uses `render_document()` for the same purpose.
signalwire.core.swml_service.SWMLService.render_pretty: PHP convenience for human-readable JSON output; Python equivalent is `render_document(pretty=True)`.
signalwire.core.swml_service.SWMLService.render_swml: PHP alias for the SWML render path; equivalent of Python's `render_document`.
signalwire.core.swml_service.SWMLService.run: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
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
signalwire.relay.call.Call.pass: PHP idiomatic getter on Call; Python's Call class exposes the same data via direct attribute access.
signalwire.relay.call.Call.resolve_all_actions: PHP idiomatic getter on Call; Python's Call class exposes the same data via direct attribute access.
signalwire.relay.call.CollectAction.get_collect_result: PHP idiomatic accessor on the CollectAction subclass.
signalwire.relay.call.CollectAction.get_stop_method: PHP idiomatic accessor on the CollectAction subclass.
signalwire.relay.call.CollectAction.handle_event: PHP idiomatic accessor on the CollectAction subclass.
signalwire.relay.call.DetectAction.get_detect_result: PHP idiomatic accessor on the DetectAction subclass.
signalwire.relay.call.DetectAction.get_stop_method: PHP idiomatic accessor on the DetectAction subclass.
signalwire.relay.call.CollectAction.set_stop_method: PHP CollectAction is the handle for both calling.collect and calling.play_and_collect (Python uses two separate types); the setter lets startAction wire the right verb-specific stop sub-command.
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
signalwire.relay.message.Message.handle_event: alias for dispatchEvent so the Client's event router (which symmetrically calls handleEvent on actions and messages) doesn't need a per-type branch.
signalwire.relay.message.Message.get_tags: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.get_to_number: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.on_completed: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.message.Message.resolve: PHP idiomatic getter on the Message class; Python users access via direct attribute reads.
signalwire.relay.relay_error.RelayError: PHP exception class corresponding to Python's `signalwire.relay.client.RelayError`; lives in its own module because PHP's autoloader is one-class-per-file.
signalwire.relay.web_socket.WebSocket: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.close: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.connect: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.is_connected: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.receive: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.relay.web_socket.WebSocket.send_text: Port-internal WebSocket transport adapter; Python uses the websockets library directly.
signalwire.rest._base.CrudResource.__init__: PHP idiomatic accessor on CrudResource (PHP exposes `getBasePath()`, `getClient()`, `getProjectId()` for advanced use).
signalwire.rest._base.CrudResource.get_base_path: PHP idiomatic accessor on CrudResource (PHP exposes `getBasePath()`, `getClient()`, `getProjectId()` for advanced use).
signalwire.rest._base.CrudResource.get_client: PHP idiomatic accessor on CrudResource (PHP exposes `getBasePath()`, `getClient()`, `getProjectId()` for advanced use).
signalwire.rest._base.HttpClient.get_auth_header: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.get_base_url: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.get_project_id: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.get_token: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.HttpClient.list_all: PHP idiomatic accessor on HttpClient.
signalwire.rest._base.SignalWireRestError.__str__: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.rest._base.SignalWireRestError.get_response_body: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.rest._base.SignalWireRestError.get_status_code: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.rest.client.RestClient.addresses: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.calling: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.chat: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.compat: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
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
signalwire.rest.client.RestClient.mfa: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.number_groups: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.phone_numbers: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.project: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.pubsub: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.queues: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.recordings: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.registry: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.short_codes: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.sip_profile: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.verified_callers: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.client.RestClient.video: PHP idiomatic getter / namespace accessor on RestClient; Python users access the same data via `client.<name>` direct attribute access (e.g. `client.fabric.subscribers`).
signalwire.rest.namespaces.calling.CallingNamespace.get_base_path: PHP idiomatic accessor on the CallingNamespace; Python exposes the same surface via the calling-namespace class methods.
signalwire.rest.namespaces.calling.CallingNamespace.get_client: PHP idiomatic accessor on the CallingNamespace; Python exposes the same surface via the calling-namespace class methods.
signalwire.rest.namespaces.calling.CallingNamespace.get_project_id: PHP idiomatic accessor on the CallingNamespace; Python exposes the same surface via the calling-namespace class methods.
signalwire.rest.namespaces.calling.CallingNamespace.update_call: PHP idiomatic accessor on the CallingNamespace; Python exposes the same surface via the calling-namespace class methods.
signalwire.rest.namespaces.fabric.FabricNamespace.addresses: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.ai_agents: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.call_flows: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.call_queues: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.conference_rooms: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.conversations: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.dial_plans: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.freeclimb_apps: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.get_client: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.phone_numbers: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.sip_endpoints: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.sip_profiles: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.subscribers: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.rest.namespaces.fabric.FabricNamespace.swml_scripts: PHP exposes a single FabricNamespace class with sub-resource accessors; Python splits into per-resource classes.
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.builtin.__module__.get_hints: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.builtin.__module__.get_prompt_sections: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
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
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.get_description: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.get_name: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.supports_multiple_instances: idiomatic PHP surface extension (getter, setter, or method alias) not present in Python's reference
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
signalwire.utils.schema_utils.SchemaUtils.generate_method_signature: Python-source codegen helper; canonical Python signatures filter this method out (Python-only output shape).
signalwire.utils.schema_utils.SchemaUtils.generate_method_body: Python-source codegen helper; canonical Python signatures filter this method out (Python-only output shape).
signalwire.utils.schema_utils.SchemaUtils.is_full_validation_available: @property in Python (filtered as bool-returning attribute); ports expose it as an explicit method per spec.
