# PORT_SIGNATURE_OMISSIONS.md

Documented signature divergences between this PHP port and the Python
reference. Names-only divergences live in PORT_OMISSIONS.md /
PORT_ADDITIONS.md and are inherited automatically.

Excused divergences fall into:

1. **Idiom-level** (deliberate, not fixable without breaking PHP API style):
   - PHP constructors follow PHP conventions; param shapes differ from Python kwargs.
   - PHP methods return ``self``/``static`` for fluent chaining; Python returns None.
   - PHP has limited optional types; many parameters are required where
     Python has defaults.

2. **Port maintenance backlog** (tracked here; will be reduced as the PHP
   port catches up to Python signature parity).


## Idiom: PHP constructors

signalwire.agent_server.AgentServer.__init__: PHP constructor signature follows PHP conventions
signalwire.core.agent_base.AgentBase.__init__: PHP constructor signature follows PHP conventions
signalwire.core.contexts.Context.__init__: PHP constructor signature follows PHP conventions
signalwire.core.contexts.GatherInfo.__init__: PHP constructor signature follows PHP conventions
signalwire.core.contexts.GatherQuestion.__init__: PHP constructor signature follows PHP conventions
signalwire.core.contexts.Step.__init__: PHP constructor signature follows PHP conventions
signalwire.core.function_result.FunctionResult.__init__: PHP constructor signature follows PHP conventions
signalwire.core.security.session_manager.SessionManager.__init__: PHP constructor signature follows PHP conventions
signalwire.core.skill_base.SkillBase.__init__: PHP constructor signature follows PHP conventions
signalwire.core.swml_service.SWMLService.__init__: PHP constructor signature follows PHP conventions
signalwire.prefabs.concierge.ConciergeAgent.__init__: PHP constructor signature follows PHP conventions
signalwire.prefabs.faq_bot.FAQBotAgent.__init__: PHP constructor signature follows PHP conventions
signalwire.prefabs.info_gatherer.InfoGathererAgent.__init__: PHP constructor signature follows PHP conventions
signalwire.prefabs.receptionist.ReceptionistAgent.__init__: PHP constructor signature follows PHP conventions
signalwire.prefabs.survey.SurveyAgent.__init__: PHP constructor signature follows PHP conventions
signalwire.relay.call.Action.__init__: PHP constructor signature follows PHP conventions
signalwire.relay.call.Call.__init__: PHP constructor signature follows PHP conventions
signalwire.relay.call.FaxAction.__init__: PHP constructor signature follows PHP conventions
signalwire.relay.client.RelayClient.__init__: PHP constructor signature follows PHP conventions
signalwire.relay.message.Message.__init__: PHP constructor signature follows PHP conventions
signalwire.relay.relay_client.RelayClient.__init__: PHP constructor signature follows PHP conventions
signalwire.rest._base.HttpClient.__init__: PHP constructor signature follows PHP conventions
signalwire.rest._base.SignalWireRestError.__init__: PHP constructor signature follows PHP conventions
signalwire.rest.client.RestClient.__init__: PHP constructor signature follows PHP conventions
signalwire.rest.namespaces.calling.CallingNamespace.__init__: PHP constructor signature follows PHP conventions
signalwire.rest.namespaces.calling_namespace.CallingNamespace.__init__: PHP constructor signature follows PHP conventions
signalwire.rest.namespaces.fabric.FabricNamespace.__init__: PHP constructor signature follows PHP conventions
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.__init__: PHP constructor signature follows PHP conventions
signalwire.search.DocumentProcessor.__init__: PHP constructor signature follows PHP conventions
signalwire.search.IndexBuilder.__init__: PHP constructor signature follows PHP conventions
signalwire.search.SearchEngine.__init__: PHP constructor signature follows PHP conventions
signalwire.search.SearchService.__init__: PHP constructor signature follows PHP conventions
signalwire.search.search_service.SearchRequest.__init__: PHP constructor signature follows PHP conventions
signalwire.search.search_service.SearchResponse.__init__: PHP constructor signature follows PHP conventions
signalwire.search.search_service.SearchResult.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.api_ninjas_trivia_skill.ApiNinjasTriviaSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.custom_skills_skill.CustomSkillsSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.date_time_skill.DateTimeSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.google_maps_skill.GoogleMapsSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.joke_skill.JokeSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.math_skill.MathSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.native_vector_search_skill.NativeVectorSearchSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.play_background_file_skill.PlayBackgroundFileSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.spider_skill.SpiderSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.weather_api_skill.WeatherApiSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.web_search_skill.WebSearchSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.skills.builtin.wikipedia_search_skill.WikipediaSearchSkill.__init__: PHP constructor signature follows PHP conventions
signalwire.swml.swml_service.SWMLService.__init__: PHP constructor signature follows PHP conventions

## Idiom: PHP fluent API returns self/static

signalwire.agent_server.AgentServer.get_agents: PHP fluent API returns self/static for chaining
signalwire.agent_server.AgentServer.register: PHP fluent API returns self/static for chaining
signalwire.agent_server.AgentServer.register_sip_username: PHP fluent API returns self/static for chaining
signalwire.agent_server.AgentServer.unregister: PHP fluent API returns self/static for chaining
signalwire.core.agent_base.AgentBase.clear_post_ai_verbs: PHP fluent API returns self/static for chaining
signalwire.core.agent_base.AgentBase.clear_post_answer_verbs: PHP fluent API returns self/static for chaining
signalwire.core.agent_base.AgentBase.clear_pre_answer_verbs: PHP fluent API returns self/static for chaining
signalwire.core.agent_base.AgentBase.clear_swaig_query_params: PHP fluent API returns self/static for chaining
signalwire.core.agent_base.AgentBase.set_post_prompt_url: PHP fluent API returns self/static for chaining
signalwire.core.agent_base.AgentBase.set_web_hook_url: PHP fluent API returns self/static for chaining
signalwire.core.contexts.ContextBuilder.reset: PHP fluent API returns self/static for chaining
signalwire.core.contexts.ContextBuilder.to_dict: PHP fluent API returns self/static for chaining
signalwire.core.contexts.ContextBuilder.validate: PHP fluent API returns self/static for chaining
signalwire.core.data_map.DataMap.to_swaig_function: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.clear_dynamic_hints: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.enable_extensive_data: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.enable_functions_on_timeout: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.execute_swml: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.hangup: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.hold: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.join_room: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.play_background_file: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.rpc_ai_unhold: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.say: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.simulate_user_input: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.sip_refer: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.stop: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.stop_background_file: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.swml_change_context: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.swml_change_step: PHP fluent API returns self/static for chaining
signalwire.core.function_result.FunctionResult.to_dict: PHP fluent API returns self/static for chaining
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_hint: PHP fluent API returns self/static for chaining
signalwire.core.mixins.prompt_mixin.PromptMixin.reset_contexts: PHP fluent API returns self/static for chaining
signalwire.core.mixins.prompt_mixin.PromptMixin.set_post_prompt: PHP fluent API returns self/static for chaining
signalwire.core.mixins.prompt_mixin.PromptMixin.set_prompt_text: PHP fluent API returns self/static for chaining
signalwire.core.mixins.skill_mixin.SkillMixin.list_skills: PHP fluent API returns self/static for chaining
signalwire.core.skill_base.SkillBase.get_global_data: PHP fluent API returns self/static for chaining
signalwire.core.skill_base.SkillBase.get_hints: PHP fluent API returns self/static for chaining
signalwire.core.skill_base.SkillBase.get_prompt_sections: PHP fluent API returns self/static for chaining
signalwire.core.skill_base.SkillBase.validate_env_vars: PHP fluent API returns self/static for chaining
signalwire.relay.call.Call.denoise: PHP fluent API returns self/static for chaining
signalwire.relay.call.Call.denoise_stop: PHP fluent API returns self/static for chaining
signalwire.relay.call.Call.disconnect: PHP fluent API returns self/static for chaining
signalwire.relay.call.Call.hold: PHP fluent API returns self/static for chaining
signalwire.relay.call.Call.unhold: PHP fluent API returns self/static for chaining
signalwire.skills.registry.SkillRegistry.list_skills: PHP fluent API returns self/static for chaining

## Backlog: real signature divergences (539 symbols)

signalwire.agent_server.AgentServer.run: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 1/ reference=['self', 'event', 'context', 'ho; return-mismatch/
signalwire.agent_server.AgentServer.serve_static_files: BACKLOG / param-mismatch/ param[2] (route)/ name 'route' vs 'url_prefix'; required False vs True; default ; return-mismatch/ retur
signalwire.agent_server.AgentServer.setup_sip_routing: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 1/ reference=['self', 'route', 'auto_map'] po; return-mismatch/
signalwire.core.agent_base.AgentBase.add_answer_verb: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 3/ reference=['self', 'config'] port=['self',; return-mismatch/
signalwire.core.agent_base.AgentBase.add_post_ai_verb: BACKLOG / param-mismatch/ param[1] (verb_name)/ name 'verb_name' vs 'verb'; param-mismatch/ param[2] (config)/ type 'dict<string,a
signalwire.core.agent_base.AgentBase.add_post_answer_verb: BACKLOG / param-mismatch/ param[1] (verb_name)/ name 'verb_name' vs 'verb'; param-mismatch/ param[2] (config)/ type 'dict<string,a
signalwire.core.agent_base.AgentBase.add_pre_answer_verb: BACKLOG / param-mismatch/ param[1] (verb_name)/ name 'verb_name' vs 'verb'; param-mismatch/ param[2] (config)/ type 'dict<string,a
signalwire.core.agent_base.AgentBase.add_swaig_query_params: BACKLOG / param-mismatch/ param[1] (params)/ type 'dict<string,string>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.
signalwire.core.agent_base.AgentBase.enable_sip_routing: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 1/ reference=['self', 'auto_map', 'path'] por; return-mismatch/
signalwire.core.agent_base.AgentBase.on_debug_event: BACKLOG / param-mismatch/ param[1] (handler)/ name 'handler' vs 'callback'; type 'class/Callable' vs 'call; return-mismatch/ retur
signalwire.core.agent_base.AgentBase.on_summary: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 2/ reference=['self', 'summary', 'raw_data'] ; return-mismatch/
signalwire.core.agent_base.AgentBase.register_sip_username: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 3/ reference=['self', 'sip_username'] port=['; return-mismatch/
signalwire.core.contexts.Context.add_bullets: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.add_enter_filler: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.add_exit_filler: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.add_section: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.add_step: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.add_system_bullets: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.add_system_section: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.get_step: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.move_step: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.remove_step: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_consolidate: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_enter_fillers: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_exit_fillers: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_full_reset: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_initial_step: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_isolated: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_post_prompt: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_prompt: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_system_prompt: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_user_prompt: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_valid_contexts: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.set_valid_steps: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Context.to_dict: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.GatherInfo.add_question: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.GatherInfo.to_dict: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.GatherQuestion.to_dict: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.add_bullets: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.add_gather_question: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.add_section: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.clear_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_end: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_functions: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_gather_info: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_reset_consolidate: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_reset_full_reset: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_reset_system_prompt: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_reset_user_prompt: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_skip_to_next_step: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_skip_user_turn: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_step_criteria: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_text: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_valid_contexts: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.set_valid_steps: BACKLOG / missing-port/ in reference, not in port
signalwire.core.contexts.Step.to_dict: BACKLOG / missing-port/ in reference, not in port
signalwire.core.data_map.DataMap.body: BACKLOG / param-mismatch/ param[1] (data)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.data_
signalwire.core.data_map.DataMap.description: BACKLOG / param-mismatch/ param[1] (description)/ name 'description' vs 'desc'; return-mismatch/ returns 'class/signalwire.core.da
signalwire.core.data_map.DataMap.error_keys: BACKLOG / param-mismatch/ param[1] (keys)/ type 'list<string>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.data_map.
signalwire.core.data_map.DataMap.expression: BACKLOG / param-mismatch/ param[2] (pattern)/ type 'union<class/Pattern,string>' vs 'string'; param-mismatch/ param[3] (output)/ t
signalwire.core.data_map.DataMap.fallback_output: BACKLOG / param-mismatch/ param[1] (result)/ type 'class/signalwire.core.function_result.FunctionResult' v; return-mismatch/ retur
signalwire.core.data_map.DataMap.foreach: BACKLOG / param-mismatch/ param[1] (foreach_config)/ name 'foreach_config' vs 'config'; type 'dict<string,; return-mismatch/ retur
signalwire.core.data_map.DataMap.global_error_keys: BACKLOG / param-mismatch/ param[1] (keys)/ type 'list<string>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.data_map.
signalwire.core.data_map.DataMap.output: BACKLOG / param-mismatch/ param[1] (result)/ type 'class/signalwire.core.function_result.FunctionResult' v; return-mismatch/ retur
signalwire.core.data_map.DataMap.parameter: BACKLOG / param-mismatch/ param[2] (param_type)/ name 'param_type' vs 'type'; param-mismatch/ param[5] (enum)/ type 'optional<list
signalwire.core.data_map.DataMap.params: BACKLOG / param-mismatch/ param[1] (data)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.data_
signalwire.core.data_map.DataMap.purpose: BACKLOG / param-mismatch/ param[1] (description)/ name 'description' vs 'desc'; return-mismatch/ returns 'class/signalwire.core.da
signalwire.core.data_map.DataMap.webhook: BACKLOG / param-mismatch/ param[3] (headers)/ type 'optional<dict<string,string>>' vs 'any'; default None ; param-mismatch/ param[
signalwire.core.data_map.DataMap.webhook_expressions: BACKLOG / param-mismatch/ param[1] (expressions)/ type 'list<dict<string,any>>' vs 'any'; return-mismatch/ returns 'class/signalwi
signalwire.core.function_result.FunctionResult.add_action: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 2/ reference=['self', 'name', 'data'] port=['; return-mismatch/
signalwire.core.function_result.FunctionResult.add_actions: BACKLOG / param-mismatch/ param[1] (actions)/ type 'list<dict<string,any>>' vs 'any'; return-mismatch/ returns 'class/signalwire.c
signalwire.core.function_result.FunctionResult.add_dynamic_hints: BACKLOG / param-mismatch/ param[1] (hints)/ type 'list<union<dict<string,any>,string>>' vs 'any'; return-mismatch/ returns 'class/
signalwire.core.function_result.FunctionResult.connect: BACKLOG / param-mismatch/ param[2] (final)/ default True vs False; param-mismatch/ param[3] (from_addr)/ name 'from_addr' vs 'from
signalwire.core.function_result.FunctionResult.create_payment_action: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 4/ reference=['action_type', 'phrase'] port=[; return-mismatch/
signalwire.core.function_result.FunctionResult.create_payment_parameter: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 3/ reference=['name', 'value'] port=['name', ; return-mismatch/
signalwire.core.function_result.FunctionResult.create_payment_prompt: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 3/ reference=['for_situation', 'actions', 'ca; return-mismatch/
signalwire.core.function_result.FunctionResult.execute_rpc: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 3/ reference=['self', 'method', 'params', 'ca; return-mismatch/
signalwire.core.function_result.FunctionResult.join_conference: BACKLOG / param-count-mismatch/ reference has 19 param(s), port has 5/ reference=['self', 'name', 'muted', 'beep; return-mismatch/
signalwire.core.function_result.FunctionResult.pay: BACKLOG / param-count-mismatch/ reference has 20 param(s), port has 6/ reference=['self', 'payment_connector_url; return-mismatch/
signalwire.core.function_result.FunctionResult.record_call: BACKLOG / param-count-mismatch/ reference has 12 param(s), port has 5/ reference=['self', 'control_id', 'stereo'; return-mismatch/
signalwire.core.function_result.FunctionResult.remove_global_data: BACKLOG / param-mismatch/ param[1] (keys)/ type 'union<list<string>,string>' vs 'any'; return-mismatch/ returns 'class/signalwire.
signalwire.core.function_result.FunctionResult.remove_metadata: BACKLOG / param-mismatch/ param[1] (keys)/ type 'union<list<string>,string>' vs 'any'; return-mismatch/ returns 'class/signalwire.
signalwire.core.function_result.FunctionResult.replace_in_history: BACKLOG / param-mismatch/ param[1] (text)/ type 'union<bool,string>' vs 'any'; required False vs True; def; return-mismatch/ retur
signalwire.core.function_result.FunctionResult.rpc_ai_message: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 3/ reference=['self', 'call_id', 'message_tex; return-mismatch/
signalwire.core.function_result.FunctionResult.rpc_dial: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 6/ reference=['self', 'to_number', 'from_numb; return-mismatch/
signalwire.core.function_result.FunctionResult.send_sms: BACKLOG / param-count-mismatch/ reference has 7 param(s), port has 6/ reference=['self', 'to_number', 'from_numb; return-mismatch/
signalwire.core.function_result.FunctionResult.set_end_of_speech_timeout: BACKLOG / param-mismatch/ param[1] (milliseconds)/ name 'milliseconds' vs 'ms'; return-mismatch/ returns 'class/signalwire.core.fu
signalwire.core.function_result.FunctionResult.set_metadata: BACKLOG / param-mismatch/ param[1] (data)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.funct
signalwire.core.function_result.FunctionResult.set_post_process: BACKLOG / param-mismatch/ param[1] (post_process)/ name 'post_process' vs 'val'; return-mismatch/ returns 'class/signalwire.core.f
signalwire.core.function_result.FunctionResult.set_response: BACKLOG / param-mismatch/ param[1] (response)/ name 'response' vs 'text'; return-mismatch/ returns 'class/signalwire.core.function
signalwire.core.function_result.FunctionResult.set_speech_event_timeout: BACKLOG / param-mismatch/ param[1] (milliseconds)/ name 'milliseconds' vs 'ms'; return-mismatch/ returns 'class/signalwire.core.fu
signalwire.core.function_result.FunctionResult.stop_record_call: BACKLOG / param-mismatch/ param[1] (control_id)/ type 'optional<string>' vs 'string'; default None vs ''; return-mismatch/ returns
signalwire.core.function_result.FunctionResult.stop_tap: BACKLOG / param-mismatch/ param[1] (control_id)/ type 'optional<string>' vs 'string'; default None vs ''; return-mismatch/ returns
signalwire.core.function_result.FunctionResult.switch_context: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 6/ reference=['self', 'system_prompt', 'user_; return-mismatch/
signalwire.core.function_result.FunctionResult.swml_transfer: BACKLOG / param-mismatch/ param[2] (ai_response)/ required True vs False; default '<absent>' vs ''; param-mismatch/ param[3] (fina
signalwire.core.function_result.FunctionResult.swml_user_event: BACKLOG / param-mismatch/ param[1] (event_data)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core
signalwire.core.function_result.FunctionResult.tap: BACKLOG / param-count-mismatch/ reference has 7 param(s), port has 5/ reference=['self', 'uri', 'control_id', 'd; return-mismatch/
signalwire.core.function_result.FunctionResult.toggle_functions: BACKLOG / param-mismatch/ param[1] (function_toggles)/ name 'function_toggles' vs 'toggles'; type 'list<di; return-mismatch/ retur
signalwire.core.function_result.FunctionResult.update_global_data: BACKLOG / param-mismatch/ param[1] (data)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.funct
signalwire.core.function_result.FunctionResult.update_settings: BACKLOG / param-mismatch/ param[1] (settings)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.f
signalwire.core.function_result.FunctionResult.wait_for_user: BACKLOG / param-mismatch/ param[3] (answer_first)/ type 'bool' vs 'optional<bool>'; default False vs None; return-mismatch/ return
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_function_include: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 2/ reference=['self', 'url', 'functions', 'me; return-mismatch/
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_hints: BACKLOG / param-mismatch/ param[1] (hints)/ type 'list<string>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.agent_ba
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_internal_filler: BACKLOG / param-mismatch/ param[1] (function_name)/ name 'function_name' vs 'filler_or_function'; param-mismatch/ param[2] (langua
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_language: BACKLOG / param-count-mismatch/ reference has 8 param(s), port has 4/ reference=['self', 'name', 'code', 'voice'; return-mismatch/
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_pattern_hint: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 2/ reference=['self', 'hint', 'pattern', 'rep; return-mismatch/
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.add_pronunciation: BACKLOG / param-mismatch/ param[2] (with_text)/ name 'with_text' vs 'with'; param-mismatch/ param[3] (ignore_case)/ name 'ignore_c
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.enable_debug_events: BACKLOG / param-mismatch/ param[1] (level)/ type 'int' vs 'string'; default 1 vs 'all'; return-mismatch/ returns 'class/signalwire
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_function_includes: BACKLOG / param-mismatch/ param[1] (includes)/ type 'list<dict<string,any>>' vs 'any'; return-mismatch/ returns 'class/signalwire.
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_global_data: BACKLOG / param-mismatch/ param[1] (data)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.agent
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_internal_fillers: BACKLOG / param-mismatch/ param[1] (internal_fillers)/ name 'internal_fillers' vs 'fillers'; type 'dict<st; return-mismatch/ retur
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_languages: BACKLOG / param-mismatch/ param[1] (languages)/ type 'list<dict<string,any>>' vs 'any'; return-mismatch/ returns 'class/signalwire
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_native_functions: BACKLOG / param-mismatch/ param[1] (function_names)/ name 'function_names' vs 'functions'; type 'list<stri; return-mismatch/ retur
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_param: BACKLOG / param-mismatch/ param[2] (value)/ type 'any' vs 'optional<any>'; return-mismatch/ returns 'class/signalwire.core.agent_b
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_params: BACKLOG / param-mismatch/ param[1] (params)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.age
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_post_prompt_llm_params: BACKLOG / param-mismatch/ param[1] (params)/ kind 'var_keyword' vs 'positional'; required False vs True; d; return-mismatch/ retur
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_prompt_llm_params: BACKLOG / param-mismatch/ param[1] (params)/ kind 'var_keyword' vs 'positional'; required False vs True; d; return-mismatch/ retur
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.set_pronunciations: BACKLOG / param-mismatch/ param[1] (pronunciations)/ type 'list<dict<string,any>>' vs 'any'; return-mismatch/ returns 'class/signa
signalwire.core.mixins.ai_config_mixin.AIConfigMixin.update_global_data: BACKLOG / param-mismatch/ param[1] (data)/ type 'dict<string,any>' vs 'any'; return-mismatch/ returns 'class/signalwire.core.agent
signalwire.core.mixins.auth_mixin.AuthMixin.get_basic_auth_credentials: BACKLOG / missing-port/ in reference, not in port
signalwire.core.mixins.prompt_mixin.PromptMixin.contexts: BACKLOG / missing-reference/ in port, not in reference
signalwire.core.mixins.prompt_mixin.PromptMixin.define_contexts: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 1/ reference=['self', 'contexts'] port=['self; return-mismatch/
signalwire.core.mixins.prompt_mixin.PromptMixin.get_prompt: BACKLOG / return-mismatch/ returns 'union<list<dict<string,any>>,string>' vs 'union<any,string>'
signalwire.core.mixins.prompt_mixin.PromptMixin.prompt_add_section: BACKLOG / param-count-mismatch/ reference has 7 param(s), port has 4/ reference=['self', 'title', 'body', 'bulle; return-mismatch/
signalwire.core.mixins.prompt_mixin.PromptMixin.prompt_add_subsection: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 4/ reference=['self', 'parent_title', 'title'; return-mismatch/
signalwire.core.mixins.prompt_mixin.PromptMixin.prompt_add_to_section: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 4/ reference=['self', 'title', 'body', 'bulle; return-mismatch/
signalwire.core.mixins.skill_mixin.SkillMixin.add_skill: BACKLOG / param-mismatch/ param[1] (skill_name)/ name 'skill_name' vs 'name'; param-mismatch/ param[2] (params)/ type 'optional<di
signalwire.core.mixins.skill_mixin.SkillMixin.has_skill: BACKLOG / param-mismatch/ param[1] (skill_name)/ name 'skill_name' vs 'name'
signalwire.core.mixins.skill_mixin.SkillMixin.remove_skill: BACKLOG / param-mismatch/ param[1] (skill_name)/ name 'skill_name' vs 'name'; return-mismatch/ returns 'class/signalwire.core.agen
signalwire.core.mixins.tool_mixin.ToolMixin.define_tool: BACKLOG / missing-port/ in reference, not in port
signalwire.core.mixins.tool_mixin.ToolMixin.define_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.core.mixins.tool_mixin.ToolMixin.on_function_call: BACKLOG / missing-port/ in reference, not in port
signalwire.core.mixins.tool_mixin.ToolMixin.register_swaig_function: BACKLOG / missing-port/ in reference, not in port
signalwire.core.mixins.web_mixin.WebMixin.manual_set_proxy_url: BACKLOG / param-mismatch/ param[1] (proxy_url)/ name 'proxy_url' vs 'url'; return-mismatch/ returns 'class/signalwire.core.agent_b
signalwire.core.mixins.web_mixin.WebMixin.set_dynamic_config_callback: BACKLOG / param-mismatch/ param[1] (callback)/ type 'callable<list<dict<any,any>,dict<any,any>,dict<any,an; return-mismatch/ retur
signalwire.core.skill_base.SkillBase.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.core.skill_base.SkillBase.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.core.skill_base.SkillBase.get_parameter_schema: BACKLOG / param-mismatch/ param[0] (cls)/ name 'cls' vs 'self'; kind 'cls' vs 'self'; return-mismatch/ returns 'dict<string,dict<s
signalwire.core.skill_manager.SkillManager.get_skill: BACKLOG / param-mismatch/ param[1] (skill_identifier)/ name 'skill_identifier' vs 'key'
signalwire.core.skill_manager.SkillManager.has_skill: BACKLOG / param-mismatch/ param[1] (skill_identifier)/ name 'skill_identifier' vs 'key'
signalwire.core.skill_manager.SkillManager.load_skill: BACKLOG / param-mismatch/ param[2] (skill_class)/ name 'skill_class' vs 'params'; type 'class/signalwire.c; param-mismatch/ param[
signalwire.core.skill_manager.SkillManager.unload_skill: BACKLOG / param-mismatch/ param[1] (skill_identifier)/ name 'skill_identifier' vs 'key'
signalwire.core.swml_service.SWMLService.extract_sip_username: BACKLOG / missing-port/ in reference, not in port
signalwire.core.swml_service.SWMLService.get_basic_auth_credentials: BACKLOG / missing-port/ in reference, not in port
signalwire.core.swml_service.SWMLService.get_document: BACKLOG / missing-port/ in reference, not in port
signalwire.core.swml_service.SWMLService.register_routing_callback: BACKLOG / missing-port/ in reference, not in port
signalwire.core.swml_service.SWMLService.serve: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.call.Action.is_done: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Action.wait: BACKLOG / param-mismatch/ param[1] (timeout)/ type 'optional<float>' vs 'int'; default None vs 30; return-mismatch/ returns 'class
signalwire.relay.call.Call.actions: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.ai: BACKLOG / param-count-mismatch/ reference has 16 param(s), port has 2/ reference=['self', 'control_id', 'agent',
signalwire.relay.call.Call.ai_hold: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 1/ reference=['self', 'timeout', 'prompt', 'k; return-mismatch/
signalwire.relay.call.Call.ai_message: BACKLOG / param-count-mismatch/ reference has 6 param(s), port has 2/ reference=['self', 'message_text', 'role',; return-mismatch/
signalwire.relay.call.Call.ai_unhold: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 1/ reference=['self', 'prompt', 'kwargs'] por; return-mismatch/
signalwire.relay.call.Call.amazon_bedrock: BACKLOG / param-count-mismatch/ reference has 8 param(s), port has 2/ reference=['self', 'prompt', 'SWAIG', 'ai_; return-mismatch/
signalwire.relay.call.Call.answer: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 1/ reference=['self', 'kwargs'] port=['self']; return-mismatch/
signalwire.relay.call.Call.bind_digit: BACKLOG / param-count-mismatch/ reference has 7 param(s), port has 2/ reference=['self', 'digits', 'bind_method'; return-mismatch/
signalwire.relay.call.Call.call_id: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.clear_digit_bindings: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 1/ reference=['self', 'realm', 'kwargs'] port; return-mismatch/
signalwire.relay.call.Call.client: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.collect: BACKLOG / param-count-mismatch/ reference has 11 param(s), port has 2/ reference=['self', 'digits', 'speech', 'i; return-mismatch/
signalwire.relay.call.Call.connect: BACKLOG / param-count-mismatch/ reference has 8 param(s), port has 2/ reference=['self', 'devices', 'ringback', ; return-mismatch/
signalwire.relay.call.Call.context: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.detect: BACKLOG / param-count-mismatch/ reference has 6 param(s), port has 2/ reference=['self', 'detect', 'timeout', 'c
signalwire.relay.call.Call.device: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.dial_winner: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.echo: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 1/ reference=['self', 'timeout', 'status_url'; return-mismatch/
signalwire.relay.call.Call.end_reason: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.hangup: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 1/ reference=['self', 'reason'] port=['self']; return-mismatch/
signalwire.relay.call.Call.join_conference: BACKLOG / param-count-mismatch/ reference has 22 param(s), port has 2/ reference=['self', 'name', 'muted', 'beep; return-mismatch/
signalwire.relay.call.Call.join_room: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 2/ reference=['self', 'name', 'status_url', '; return-mismatch/
signalwire.relay.call.Call.leave_conference: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 1/ reference=['self', 'conference_id', 'kwarg; return-mismatch/
signalwire.relay.call.Call.leave_room: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 1/ reference=['self', 'kwargs'] port=['self']; return-mismatch/
signalwire.relay.call.Call.live_transcribe: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 2/ reference=['self', 'action', 'kwargs'] por; return-mismatch/
signalwire.relay.call.Call.live_translate: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 2/ reference=['self', 'action', 'status_url',; return-mismatch/
signalwire.relay.call.Call.node_id: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.on: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 2/ reference=['self', 'event_type', 'handler'; return-mismatch/
signalwire.relay.call.Call.on_event_callbacks: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.pay: BACKLOG / param-count-mismatch/ reference has 22 param(s), port has 2/ reference=['self', 'payment_connector_url
signalwire.relay.call.Call.peer: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.play: BACKLOG / param-count-mismatch/ reference has 8 param(s), port has 2/ reference=['self', 'media', 'volume', 'dir
signalwire.relay.call.Call.play_and_collect: BACKLOG / param-count-mismatch/ reference has 7 param(s), port has 2/ reference=['self', 'media', 'collect', 'vo
signalwire.relay.call.Call.queue_enter: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 2/ reference=['self', 'queue_name', 'control_; return-mismatch/
signalwire.relay.call.Call.queue_leave: BACKLOG / param-count-mismatch/ reference has 6 param(s), port has 1/ reference=['self', 'queue_name', 'control_; return-mismatch/
signalwire.relay.call.Call.receive_fax: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 2/ reference=['self', 'control_id', 'on_compl
signalwire.relay.call.Call.record: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 2/ reference=['self', 'audio', 'control_id', 
signalwire.relay.call.Call.refer: BACKLOG / param-count-mismatch/ reference has 4 param(s), port has 2/ reference=['self', 'device', 'status_url',; return-mismatch/
signalwire.relay.call.Call.send_digits: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 2/ reference=['self', 'digits', 'control_id']; return-mismatch/
signalwire.relay.call.Call.send_fax: BACKLOG / param-count-mismatch/ reference has 7 param(s), port has 2/ reference=['self', 'document', 'identity',
signalwire.relay.call.Call.state: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.stream: BACKLOG / param-count-mismatch/ reference has 12 param(s), port has 2/ reference=['self', 'url', 'name', 'codec'
signalwire.relay.call.Call.tag: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.call.Call.tap: BACKLOG / param-count-mismatch/ reference has 6 param(s), port has 2/ reference=['self', 'tap', 'device', 'contr
signalwire.relay.call.Call.transcribe: BACKLOG / param-count-mismatch/ reference has 5 param(s), port has 2/ reference=['self', 'control_id', 'status_u
signalwire.relay.call.Call.transfer: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 2/ reference=['self', 'dest', 'kwargs'] port=; return-mismatch/
signalwire.relay.call.Call.user_event: BACKLOG / param-count-mismatch/ reference has 3 param(s), port has 2/ reference=['self', 'event', 'kwargs'] port; return-mismatch/
signalwire.relay.call.CollectAction.start_input_timers: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.call.PlayAction.pause: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.call.PlayAction.resume: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.call.PlayAction.volume: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.call.RecordAction.pause: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.call.RecordAction.resume: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.connect: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.dial: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.disconnect: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.execute: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.on_call: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.on_message: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.receive: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.run: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.send_message: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.client.RelayClient.unreceive: BACKLOG / missing-port/ in reference, not in port
signalwire.relay.message.Message.is_done: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.message.Message.on: BACKLOG / param-mismatch/ param[1] (handler)/ name 'handler' vs 'cb'; type 'class/Callable' vs 'callable<l; return-mismatch/ retur
signalwire.relay.message.Message.wait: BACKLOG / param-mismatch/ param[1] (timeout)/ type 'optional<float>' vs 'int'; default None vs 30; return-mismatch/ returns 'class
signalwire.relay.relay_client.RelayClient.agent: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.authenticate: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.authorization_state: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.calls: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.connect: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.connected: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.contexts: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.dial: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.disconnect: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.execute: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.get_call: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.get_calls: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.get_messages: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.handle_event: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.handle_message: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.host: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.messages: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.on_call: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.on_call_handler: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.on_event_handler: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.on_message: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.on_message_handler: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.pending: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.pending_dials: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.project: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.protocol: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.read_once: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.receive: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.reconnect: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.relay_path: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.run: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.scheme: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.send: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.send_ack: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.send_message: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.session_id: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.token: BACKLOG / missing-reference/ in port, not in reference
signalwire.relay.relay_client.RelayClient.unreceive: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.CrudResource.create: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.CrudResource.delete: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.CrudResource.get: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.CrudResource.list: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.CrudResource.update: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.HttpClient.delete: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.HttpClient.get: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.HttpClient.patch: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.HttpClient.post: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest._base.HttpClient.put: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling.CallingNamespace.ai_hold: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.ai_message: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.ai_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.ai_unhold: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.collect: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.collect_start_input_timers: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.collect_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.denoise: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.denoise_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.detect: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.detect_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.dial: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.disconnect: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.end: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.live_transcribe: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.live_translate: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.play: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.play_pause: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.play_resume: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.play_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.play_volume: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.receive_fax_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.record: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.record_pause: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.record_resume: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.record_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.refer: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.send_fax_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.stream: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.stream_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.tap: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.tap_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.transcribe: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.transcribe_stop: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.transfer: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling.CallingNamespace.user_event: BACKLOG / missing-port/ in reference, not in port
signalwire.rest.namespaces.calling_namespace.CallingNamespace.ai_hold: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.ai_message: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.ai_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.ai_unhold: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.collect: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.collect_start_input_timers: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.collect_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.denoise: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.denoise_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.detect: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.detect_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.dial: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.disconnect: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.end: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.get_base_path: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.get_client: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.get_project_id: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.live_transcribe: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.live_translate: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.play: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.play_pause: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.play_resume: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.play_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.play_volume: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.receive_fax_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.record: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.record_pause: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.record_resume: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.record_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.refer: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.send_fax_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.stream: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.stream_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.tap: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.tap_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.transcribe: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.transcribe_stop: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.transfer: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.update_call: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.calling_namespace.CallingNamespace.user_event: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.addresses: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.ai_agents: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.call_flows: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.call_queues: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.conference_rooms: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.conversations: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.dial_plans: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.freeclimb_apps: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.get_client: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.phone_numbers: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.sip_endpoints: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.sip_profiles: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.subscribers: BACKLOG / missing-reference/ in port, not in reference
signalwire.rest.namespaces.fabric_namespace.FabricNamespace.swml_scripts: BACKLOG / missing-reference/ in port, not in reference
signalwire.search.preprocess_document_content: BACKLOG / missing-port/ in reference, not in port
signalwire.search.preprocess_query: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.api_ninjas_trivia.skill.ApiNinjasTriviaSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.builtin.api_ninjas_trivia_skill.ApiNinjasTriviaSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.api_ninjas_trivia_skill.ApiNinjasTriviaSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.api_ninjas_trivia_skill.ApiNinjasTriviaSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.api_ninjas_trivia_skill.ApiNinjasTriviaSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.api_ninjas_trivia_skill.ApiNinjasTriviaSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.get_hints: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.claude_skills_skill.ClaudeSkillsSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.custom_skills_skill.CustomSkillsSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.custom_skills_skill.CustomSkillsSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.custom_skills_skill.CustomSkillsSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.custom_skills_skill.CustomSkillsSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.custom_skills_skill.CustomSkillsSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.get_global_data: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_serverless_skill.DataSphereServerlessSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.get_global_data: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.data_sphere_skill.DataSphereSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.date_time_skill.DateTimeSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.date_time_skill.DateTimeSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.date_time_skill.DateTimeSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.date_time_skill.DateTimeSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.date_time_skill.DateTimeSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.google_maps_skill.GoogleMapsSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.google_maps_skill.GoogleMapsSkill.get_hints: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.google_maps_skill.GoogleMapsSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.google_maps_skill.GoogleMapsSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.google_maps_skill.GoogleMapsSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.google_maps_skill.GoogleMapsSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.get_global_data: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.info_gatherer_skill.InfoGathererSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.joke_skill.JokeSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.joke_skill.JokeSkill.get_global_data: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.joke_skill.JokeSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.joke_skill.JokeSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.joke_skill.JokeSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.joke_skill.JokeSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.math_skill.MathSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.math_skill.MathSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.math_skill.MathSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.math_skill.MathSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.math_skill.MathSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.get_global_data: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.get_hints: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.mcp_gateway_skill.MCPGatewaySkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.native_vector_search_skill.NativeVectorSearchSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.native_vector_search_skill.NativeVectorSearchSkill.get_hints: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.native_vector_search_skill.NativeVectorSearchSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.native_vector_search_skill.NativeVectorSearchSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.native_vector_search_skill.NativeVectorSearchSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.native_vector_search_skill.NativeVectorSearchSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.play_background_file_skill.PlayBackgroundFileSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.play_background_file_skill.PlayBackgroundFileSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.play_background_file_skill.PlayBackgroundFileSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.play_background_file_skill.PlayBackgroundFileSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.play_background_file_skill.PlayBackgroundFileSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.spider_skill.SpiderSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.spider_skill.SpiderSkill.get_hints: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.spider_skill.SpiderSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.spider_skill.SpiderSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.spider_skill.SpiderSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.spider_skill.SpiderSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.get_hints: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.swml_transfer_skill.SWMLTransferSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.weather_api_skill.WeatherApiSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.weather_api_skill.WeatherApiSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.weather_api_skill.WeatherApiSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.weather_api_skill.WeatherApiSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.get_global_data: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.get_version: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.web_search_skill.WebSearchSkill.supports_multiple_instances: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.wikipedia_search_skill.WikipediaSearchSkill.get_description: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.wikipedia_search_skill.WikipediaSearchSkill.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.wikipedia_search_skill.WikipediaSearchSkill.get_prompt_sections: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.wikipedia_search_skill.WikipediaSearchSkill.register_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.builtin.wikipedia_search_skill.WikipediaSearchSkill.setup: BACKLOG / missing-reference/ in port, not in reference
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.claude_skills.skill.ClaudeSkillsSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere.skill.DataSphereSkill.get_global_data: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere.skill.DataSphereSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere.skill.DataSphereSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere.skill.DataSphereSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.get_global_data: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datasphere_serverless.skill.DataSphereServerlessSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datetime.skill.DateTimeSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datetime.skill.DateTimeSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.datetime.skill.DateTimeSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.google_maps.skill.GoogleMapsSkill.get_hints: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.google_maps.skill.GoogleMapsSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.google_maps.skill.GoogleMapsSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.google_maps.skill.GoogleMapsSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.info_gatherer.skill.InfoGathererSkill.get_global_data: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.info_gatherer.skill.InfoGathererSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.info_gatherer.skill.InfoGathererSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.joke.skill.JokeSkill.get_global_data: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.joke.skill.JokeSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.joke.skill.JokeSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.joke.skill.JokeSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.math.skill.MathSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.math.skill.MathSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.math.skill.MathSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.get_global_data: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.get_hints: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.mcp_gateway.skill.MCPGatewaySkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.get_hints: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.play_background_file.skill.PlayBackgroundFileSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.registry.SkillRegistry.register_skill: BACKLOG / param-count-mismatch/ reference has 2 param(s), port has 3/ reference=['self', 'skill_class'] port=['s
signalwire.skills.spider.skill.SpiderSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.spider.skill.SpiderSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.swml_transfer.skill.SWMLTransferSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.weather_api.skill.WeatherApiSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.weather_api.skill.WeatherApiSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.web_search.skill.WebSearchSkill.get_global_data: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.web_search.skill.WebSearchSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.web_search.skill.WebSearchSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.web_search.skill.WebSearchSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.get_prompt_sections: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.register_tools: BACKLOG / missing-port/ in reference, not in port
signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.setup: BACKLOG / missing-port/ in reference, not in port
signalwire.swml.swml_service.SWMLService.define_tool: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.define_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.dispatch_from_globals: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.extract_sip_username: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_basic_auth_credentials: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_document: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_full_url: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_host: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_name: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_port: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_proxy_url_base: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_route: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_tool_names: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.get_tools: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.handle_request: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.on_function_call: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.register_routing_callback: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.register_swaig_function: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.render: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.render_pretty: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.render_swml: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.run: BACKLOG / missing-reference/ in port, not in reference
signalwire.swml.swml_service.SWMLService.serve: BACKLOG / missing-reference/ in port, not in reference
