<?php

declare(strict_types=1);

namespace SignalWire\SWML;

/**
 * Fluent builder for SWML documents.
 *
 * Provides a fluent interface for building SWML documents by chaining method
 * calls. It delegates to an underlying {@see Service} instance for the actual
 * document creation, mirroring Python's
 * `signalwire/core/swml_builder.py::SWMLBuilder` (which wraps an SWMLService)
 * and the TypeScript `SwmlBuilder`.
 *
 * The explicit verb helpers ({@see answer()}, {@see hangup()}, {@see play()},
 * {@see ai()}, {@see say()}) cover the common verbs; every other schema verb is
 * auto-vivified through {@see __call()} (e.g. `$builder->denoise()->goto(...)`),
 * the PHP analog of Python's runtime `__getattr__` verb dispatch. The `@method`
 * tags below make those auto-vivified verbs discoverable by IDEs and PHPStan.
 * (The reserved-word verbs `goto`/`return`/`switch`/`unset` dispatch the same way
 * but are omitted — they aren't callable as bare `->verb()` syntax in PHP.)
 *
 * @method static amazon_bedrock(array<string, mixed> $config = []) Auto-vivified SWML `amazon_bedrock` verb.
 * @method static cond(array<string, mixed> $config = []) Auto-vivified SWML `cond` verb.
 * @method static connect(array<string, mixed> $config = []) Auto-vivified SWML `connect` verb.
 * @method static denoise(array<string, mixed> $config = []) Auto-vivified SWML `denoise` verb.
 * @method static detect_machine(array<string, mixed> $config = []) Auto-vivified SWML `detect_machine` verb.
 * @method static enter_queue(array<string, mixed> $config = []) Auto-vivified SWML `enter_queue` verb.
 * @method static execute(array<string, mixed> $config = []) Auto-vivified SWML `execute` verb.
 * @method static join_conference(array<string, mixed> $config = []) Auto-vivified SWML `join_conference` verb.
 * @method static join_room(array<string, mixed> $config = []) Auto-vivified SWML `join_room` verb.
 * @method static label(array<string, mixed> $config = []) Auto-vivified SWML `label` verb.
 * @method static live_transcribe(array<string, mixed> $config = []) Auto-vivified SWML `live_transcribe` verb.
 * @method static live_translate(array<string, mixed> $config = []) Auto-vivified SWML `live_translate` verb.
 * @method static pay(array<string, mixed> $config = []) Auto-vivified SWML `pay` verb.
 * @method static prompt(array<string, mixed> $config = []) Auto-vivified SWML `prompt` verb.
 * @method static receive_fax(array<string, mixed> $config = []) Auto-vivified SWML `receive_fax` verb.
 * @method static record(array<string, mixed> $config = []) Auto-vivified SWML `record` verb.
 * @method static record_call(array<string, mixed> $config = []) Auto-vivified SWML `record_call` verb.
 * @method static request(array<string, mixed> $config = []) Auto-vivified SWML `request` verb.
 * @method static send_digits(array<string, mixed> $config = []) Auto-vivified SWML `send_digits` verb.
 * @method static send_fax(array<string, mixed> $config = []) Auto-vivified SWML `send_fax` verb.
 * @method static send_sms(array<string, mixed> $config = []) Auto-vivified SWML `send_sms` verb.
 * @method static set(array<string, mixed> $config = []) Auto-vivified SWML `set` verb.
 * @method static sip_refer(array<string, mixed> $config = []) Auto-vivified SWML `sip_refer` verb.
 * @method static sleep(int $duration) Auto-vivified SWML `sleep` verb (integer milliseconds).
 * @method static stop_denoise(array<string, mixed> $config = []) Auto-vivified SWML `stop_denoise` verb.
 * @method static stop_record_call(array<string, mixed> $config = []) Auto-vivified SWML `stop_record_call` verb.
 * @method static stop_tap(array<string, mixed> $config = []) Auto-vivified SWML `stop_tap` verb.
 * @method static tap(array<string, mixed> $config = []) Auto-vivified SWML `tap` verb.
 * @method static transfer(array<string, mixed> $config = []) Auto-vivified SWML `transfer` verb.
 * @method static user_event(array<string, mixed> $config = []) Auto-vivified SWML `user_event` verb.
 */
class SWMLBuilder
{
    private Service $service;

    /**
     * Initialise with a {@see Service} instance to delegate to.
     */
    public function __construct(Service $service)
    {
        $this->service = $service;
    }

    /**
     * Add an 'answer' verb to the main section.
     *
     * @param int|null    $maxDuration Maximum duration in seconds.
     * @param string|null $codecs      Comma-separated list of codecs.
     */
    public function answer(?int $maxDuration = null, ?string $codecs = null): static
    {
        $config = [];
        if ($maxDuration !== null) {
            $config['max_duration'] = $maxDuration;
        }
        if ($codecs !== null) {
            $config['codecs'] = $codecs;
        }
        $this->service->addVerb('answer', $config);
        return $this;
    }

    /**
     * Add a 'hangup' verb to the main section.
     *
     * @param string|null $reason Optional reason for hangup.
     */
    public function hangup(?string $reason = null): static
    {
        $config = [];
        if ($reason !== null) {
            $config['reason'] = $reason;
        }
        $this->service->addVerb('hangup', $config);
        return $this;
    }

    /**
     * Add an 'ai' verb to the main section.
     *
     * The SWML `ai` verb requires `prompt` to be an OBJECT — `{"text": ...}` or
     * `{"pom": [...]}`; a bare string is a fatal error in the AI engine, so the
     * text/pom form is wrapped accordingly.
     *
     * @param string|null                    $promptText    Text prompt (mutually exclusive with $promptPom).
     * @param list<array<string, mixed>>|null $promptPom     POM structure prompt (mutually exclusive with $promptText).
     * @param string|null                    $postPrompt    Optional post-prompt text.
     * @param string|null                    $postPromptUrl Optional post-prompt URL.
     * @param array<string, mixed>|null      $swaig         Optional SWAIG configuration.
     * @param array<string, mixed>           $kwargs        Additional AI parameters merged into the config.
     */
    public function ai(
        ?string $promptText = null,
        ?array $promptPom = null,
        ?string $postPrompt = null,
        ?string $postPromptUrl = null,
        ?array $swaig = null,
        array $kwargs = [],
    ): static {
        $config = [];

        if ($promptText !== null) {
            $config['prompt'] = ['text' => $promptText];
        } elseif ($promptPom !== null) {
            $config['prompt'] = ['pom' => $promptPom];
        }

        if ($postPrompt !== null) {
            $config['post_prompt'] = ['text' => $postPrompt];
        }
        if ($postPromptUrl !== null) {
            $config['post_prompt_url'] = $postPromptUrl;
        }
        if ($swaig !== null) {
            $config['SWAIG'] = $swaig;
        }

        // Merge any additional kwargs (parity with Python's config.update(kwargs)).
        foreach ($kwargs as $key => $value) {
            $config[$key] = $value;
        }

        $this->service->addVerb('ai', $config);
        return $this;
    }

    /**
     * Add a 'play' verb to the main section.
     *
     * @param string|null       $url         Single URL to play (mutually exclusive with $urls).
     * @param list<string>|null $urls        List of URLs to play (mutually exclusive with $url).
     * @param float|null        $volume      Volume level (-40 to 40).
     * @param string|null       $sayVoice    Voice for text-to-speech.
     * @param string|null       $sayLanguage Language for text-to-speech.
     * @param string|null       $sayGender   Gender for text-to-speech.
     * @param bool|null         $autoAnswer  Whether to auto-answer the call.
     */
    public function play(
        ?string $url = null,
        ?array $urls = null,
        ?float $volume = null,
        ?string $sayVoice = null,
        ?string $sayLanguage = null,
        ?string $sayGender = null,
        ?bool $autoAnswer = null,
    ): static {
        $config = [];

        if ($url !== null) {
            $config['url'] = $url;
        } elseif ($urls !== null) {
            $config['urls'] = $urls;
        } else {
            throw new \InvalidArgumentException('Either url or urls must be provided');
        }

        if ($volume !== null) {
            $config['volume'] = $volume;
        }
        if ($sayVoice !== null) {
            $config['say_voice'] = $sayVoice;
        }
        if ($sayLanguage !== null) {
            $config['say_language'] = $sayLanguage;
        }
        if ($sayGender !== null) {
            $config['say_gender'] = $sayGender;
        }
        if ($autoAnswer !== null) {
            $config['auto_answer'] = $autoAnswer;
        }

        $this->service->addVerb('play', $config);
        return $this;
    }

    /**
     * Add a 'play' verb with a `say:` prefix for text-to-speech.
     *
     * @param string      $text     Text to speak.
     * @param string|null $voice    Voice for text-to-speech.
     * @param string|null $language Language for text-to-speech.
     * @param string|null $gender   Gender for text-to-speech.
     * @param float|null  $volume   Volume level (-40 to 40).
     */
    public function say(
        string $text,
        ?string $voice = null,
        ?string $language = null,
        ?string $gender = null,
        ?float $volume = null,
    ): static {
        return $this->play(
            url: "say:{$text}",
            sayVoice: $voice,
            sayLanguage: $language,
            sayGender: $gender,
            volume: $volume,
        );
    }

    /**
     * Add a new section to the document.
     */
    public function addSection(string $sectionName): static
    {
        $this->service->addSection($sectionName);
        return $this;
    }

    /**
     * Build and return the SWML document as an associative array.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return $this->service->getDocument()->toArray();
    }

    /**
     * Build and render the SWML document as a JSON string.
     */
    public function render(): string
    {
        return $this->service->renderDocument();
    }

    /**
     * Reset the document to an empty state.
     */
    public function reset(): static
    {
        $this->service->resetDocument();
        return $this;
    }

    /**
     * Auto-vivify SWML verb methods from the schema.
     *
     * This is the PHP analog of Python's `SWMLBuilder.__getattr__` runtime verb
     * dispatch: any schema verb name not covered by an explicit method above is
     * dispatched to the underlying service, returning `$this` for chaining.
     *
     *   $builder->denoise()->record();
     *   $builder->sleep(2000);
     *
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): static
    {
        $schema = Schema::instance();
        if (!$schema->isValidVerb($method)) {
            throw new \BadMethodCallException("Unknown method: {$method}");
        }

        if ($method === 'sleep') {
            // sleep(2000) — direct integer duration.
            $duration = $args[0] ?? null;
            if (!is_int($duration)) {
                throw new \InvalidArgumentException('sleep requires an integer duration');
            }
            $this->service->addVerb('sleep', $duration);
            return $this;
        }

        // verb() or verb(['config' => ...]).
        $config = [];
        if (count($args) >= 1 && is_array($args[0])) {
            // Drop null values (parity with Python's kwargs-filtered config).
            $config = array_filter($args[0], static fn ($v): bool => $v !== null);
        }
        $this->service->addVerb($method, $config);
        return $this;
    }
}
