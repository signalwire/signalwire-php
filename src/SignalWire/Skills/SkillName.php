<?php

declare(strict_types=1);

namespace SignalWire\Skills;

/**
 * Built-in skill names as a typed, compile-time-checked closed set.
 *
 * {@see \SignalWire\Agent\AgentBase::addSkill()} accepts this enum OR a string.
 * The enum gives editor autocompletion and makes a typo fail at the call site
 * (a bare string like `'datetiem'` only fails at runtime, on the server);
 * strings keep parity with the Python reference (which uses bare `str`) and
 * still allow custom / third-party skills that aren't built in.
 *
 *     $agent->addSkill(SkillName::Datetime);   // typed, autocompleted
 *     $agent->addSkill('datetime');            // string still works (parity)
 *     $agent->addSkill('my_custom_skill');     // open set: custom skills ok
 */
enum SkillName: string
{
    case ApiNinjasTrivia      = 'api_ninjas_trivia';
    case ClaudeSkills         = 'claude_skills';
    case CustomSkills         = 'custom_skills';
    case Datasphere           = 'datasphere';
    case DatasphereServerless = 'datasphere_serverless';
    case Datetime             = 'datetime';
    case GoogleMaps           = 'google_maps';
    case InfoGatherer         = 'info_gatherer';
    case Joke                 = 'joke';
    case Math                 = 'math';
    case McpGateway           = 'mcp_gateway';
    case NativeVectorSearch   = 'native_vector_search';
    case PlayBackgroundFile   = 'play_background_file';
    case Spider               = 'spider';
    case SwmlTransfer         = 'swml_transfer';
    case WeatherApi           = 'weather_api';
    case WebSearch            = 'web_search';
    case WikipediaSearch      = 'wikipedia_search';
}
