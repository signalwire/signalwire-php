# SignalWire PHP SDK Examples

Agent examples demonstrating the AI Agent framework, SWAIG tools, DataMap, Contexts, Skills, Prefabs, RELAY, and REST.

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
| [simple_dynamic_agent.php](simple_dynamic_agent.php) | Agent configured dynamically per-request via callback |
| [multi_agent_server.php](multi_agent_server.php) | Multiple agents on one server (healthcare, finance, retail) |
| [contexts_demo.php](contexts_demo.php) | Multi-step context navigation with personas |
| [datamap_demo.php](datamap_demo.php) | DataMap tools: webhook API calls and expression matching |
| [skills_demo.php](skills_demo.php) | Modular skills system: datetime, math, web_search |
| [session_state.php](session_state.php) | Session lifecycle: global data, summary, tool actions |
| [call_flow.php](call_flow.php) | Call flow verbs, debug events, and FunctionResult actions |
| [relay_demo.php](relay_demo.php) | RELAY client: answer inbound calls and play TTS |
| [rest_demo.php](rest_demo.php) | REST client: list resources across APIs |
| [prefab_info_gatherer.php](prefab_info_gatherer.php) | InfoGatherer prefab for structured data collection |
| [prefab_survey.php](prefab_survey.php) | Survey prefab with rating, yes/no, and open-ended questions |
