# SignalWire PHP SDK Examples

Agent examples demonstrating the AI Agent framework, SWAIG tools, DataMap, Contexts, Skills, Prefabs, MCP, SWML Services, RELAY, and REST.

## Running Examples

```bash
# Install dependencies
composer install

# Set environment variables
export SIGNALWIRE_PROJECT_ID=your-project-id
export SIGNALWIRE_API_TOKEN=your-api-token
export SIGNALWIRE_SPACE=example.signalwire.com

# Run an example
php examples/simple_agent.php
```

## Agent Examples

| File | Description |
|------|-------------|
| [simple_agent.php](simple_agent.php) | Basic agent with tools, hints, languages, and summary |
| [simple_static_agent.php](simple_static_agent.php) | Static configuration agent (set once at startup) |
| [simple_dynamic_agent.php](simple_dynamic_agent.php) | Agent configured dynamically per-request via callback |
| [simple_dynamic_enhanced.php](simple_dynamic_enhanced.php) | Enhanced dynamic agent with VIP, department, and language support |
| [comprehensive_dynamic_agent.php](comprehensive_dynamic_agent.php) | Full dynamic config: tier, industry, A/B testing, multi-tenant |
| [declarative_agent.php](declarative_agent.php) | Declarative prompt sections via class-level constants |
| [custom_path_agent.php](custom_path_agent.php) | Agent with custom route/path for multi-agent setups |
| [multi_agent_server.php](multi_agent_server.php) | Multiple agents on one server (healthcare, finance, retail) |
| [multi_endpoint_agent.php](multi_endpoint_agent.php) | Agent serving multiple endpoints (SWML, health, API) |
| [faq_bot_agent.php](faq_bot_agent.php) | FAQ bot with structured knowledge base in the prompt |
| [kubernetes_ready_agent.php](kubernetes_ready_agent.php) | Production Kubernetes deployment with health checks |
| [lambda_agent.php](lambda_agent.php) | AWS Lambda deployment example |

## SWAIG and Tools

| File | Description |
|------|-------------|
| [swaig_features_agent.php](swaig_features_agent.php) | Default webhook URL, speech fillers, declarative prompts |
| [call_flow.php](call_flow.php) | Call flow verbs, debug events, and FunctionResult actions |
| [session_state.php](session_state.php) | Session lifecycle: global data, summary, tool actions |
| [record_call_example.php](record_call_example.php) | FunctionResult record_call/stop_record_call helpers |
| [room_and_sip_example.php](room_and_sip_example.php) | FunctionResult join_room, join_conference, sip_refer helpers |
| [tap_example.php](tap_example.php) | FunctionResult tap/stop_tap for call monitoring |
| [llm_params_demo.php](llm_params_demo.php) | LLM parameter tuning: precise, creative, and balanced agents |

## DataMap

| File | Description |
|------|-------------|
| [datamap_demo.php](datamap_demo.php) | DataMap tools: webhook API calls and expression matching |
| [advanced_datamap_demo.php](advanced_datamap_demo.php) | Advanced DataMap: patterns, webhooks, forms, conditionals |

## Contexts and Gather

| File | Description |
|------|-------------|
| [contexts_demo.php](contexts_demo.php) | Multi-step context navigation with personas |
| [gather_info_demo.php](gather_info_demo.php) | Contexts gather_info mode for structured data collection |

## Skills

| File | Description |
|------|-------------|
| [skills_demo.php](skills_demo.php) | Modular skills system: datetime, math, web_search |
| [joke_agent.php](joke_agent.php) | Raw data_map configuration for API Ninjas joke API |
| [joke_skill_demo.php](joke_skill_demo.php) | Joke skill using the modular skills system |
| [web_search_agent.php](web_search_agent.php) | Web search agent with Google Custom Search skill |
| [web_search_multi_instance_demo.php](web_search_multi_instance_demo.php) | Multiple search skill instances with different configs |
| [wikipedia_demo.php](wikipedia_demo.php) | Wikipedia search skill for factual information |

## DataSphere

| File | Description |
|------|-------------|
| [datasphere_multi_instance_demo.php](datasphere_multi_instance_demo.php) | Multiple DataSphere instances with different knowledge bases |
| [datasphere_serverless_demo.php](datasphere_serverless_demo.php) | DataSphere Serverless skill via DataMap (no webhook needed) |
| [datasphere_serverless_env_demo.php](datasphere_serverless_env_demo.php) | DataSphere Serverless with env var configuration |
| [datasphere_webhook_env_demo.php](datasphere_webhook_env_demo.php) | DataSphere webhook skill with env var configuration |

## MCP (Model Context Protocol)

| File | Description |
|------|-------------|
| [mcp_agent.php](mcp_agent.php) | MCP client and server: expose tools, consume external MCP servers |
| [mcp_gateway_demo.php](mcp_gateway_demo.php) | MCP Gateway skill connecting to MCP servers via gateway |

## Prefabs

| File | Description |
|------|-------------|
| [prefab_info_gatherer.php](prefab_info_gatherer.php) | InfoGatherer prefab for structured data collection |
| [prefab_survey.php](prefab_survey.php) | Survey prefab with rating, yes/no, and open-ended questions |
| [info_gatherer_example.php](info_gatherer_example.php) | InfoGatherer with static questions |
| [dynamic_info_gatherer_example.php](dynamic_info_gatherer_example.php) | InfoGatherer with dynamic questions via callback |
| [survey_agent_example.php](survey_agent_example.php) | SurveyAgent with custom feedback analysis tool |
| [receptionist_agent_example.php](receptionist_agent_example.php) | ReceptionistAgent prefab for call routing |
| [concierge_agent_example.php](concierge_agent_example.php) | ConciergeAgent prefab for venue information |

## SWML Services

| File | Description |
|------|-------------|
| [basic_swml_service.php](basic_swml_service.php) | SWML services: voicemail, IVR, call transfer |
| [auto_vivified_example.php](auto_vivified_example.php) | Auto-vivified verb methods on SWMLService |
| [swml_service_example.php](swml_service_example.php) | Direct verb manipulation and SWMLBuilder API |
| [swml_service_routing_example.php](swml_service_routing_example.php) | Custom routing callbacks for multi-path services |
| [dynamic_swml_service.php](dynamic_swml_service.php) | Dynamic SWML generation based on POST data |

## RELAY and REST

| File | Description |
|------|-------------|
| [relay_demo.php](relay_demo.php) | RELAY client: answer inbound calls and play TTS |
| [rest_demo.php](rest_demo.php) | REST client: list resources across APIs |
