<?php

declare(strict_types=1);

namespace SignalWire\Contexts;

/**
 * Reserved tool names auto-injected by the runtime when contexts/steps are
 * present. User-defined SWAIG tools must not collide with these names:
 *   - next_step / change_context are injected when valid_steps or
 *     valid_contexts is set so the model can navigate the flow.
 *   - gather_submit is injected while a step's gather_info is collecting
 *     answers.
 *
 * ContextBuilder::validate() rejects any agent that registers a user tool
 * sharing one of these names — the runtime would never call the user tool
 * because the native one wins.
 */
const RESERVED_NATIVE_TOOL_NAMES = [
    'next_step',
    'change_context',
    'gather_submit',
];

// ── GatherQuestion ──────────────────────────────────────────────────────────

class GatherQuestion
{
    private string $key;
    private string $question;
    private string $type;
    private bool $confirm;
    private ?string $prompt;
    private ?array $functions;

    public function __construct(array $opts)
    {
        $this->key = $opts['key'];
        $this->question = $opts['question'];
        $this->type = $opts['type'] ?? 'string';
        $this->confirm = $opts['confirm'] ?? false;
        $this->prompt = $opts['prompt'] ?? null;
        $this->functions = $opts['functions'] ?? null;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function toArray(): array
    {
        $map = [
            'key' => $this->key,
            'question' => $this->question,
        ];

        if ($this->type !== 'string') {
            $map['type'] = $this->type;
        }
        if ($this->confirm) {
            $map['confirm'] = true;
        }
        if ($this->prompt !== null) {
            $map['prompt'] = $this->prompt;
        }
        if ($this->functions !== null && !empty($this->functions)) {
            $map['functions'] = $this->functions;
        }

        return $map;
    }
}

// ── GatherInfo ──────────────────────────────────────────────────────────────

class GatherInfo
{
    /** @var GatherQuestion[] */
    private array $questions = [];
    private ?string $outputKey;
    private ?string $completionAction;
    private ?string $prompt;

    public function __construct(
        ?string $outputKey = null,
        ?string $completionAction = null,
        ?string $prompt = null
    ) {
        $this->outputKey = $outputKey;
        $this->completionAction = $completionAction;
        $this->prompt = $prompt;
    }

    public function addQuestion(array $opts): self
    {
        $this->questions[] = new GatherQuestion($opts);
        return $this;
    }

    /**
     * @return GatherQuestion[]
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    public function getCompletionAction(): ?string
    {
        return $this->completionAction;
    }

    public function toArray(): array
    {
        $map = [];

        $questionMaps = [];
        foreach ($this->questions as $q) {
            $questionMaps[] = $q->toArray();
        }
        $map['questions'] = $questionMaps;

        if ($this->prompt !== null) {
            $map['prompt'] = $this->prompt;
        }
        if ($this->outputKey !== null) {
            $map['output_key'] = $this->outputKey;
        }
        if ($this->completionAction !== null) {
            $map['completion_action'] = $this->completionAction;
        }

        return $map;
    }
}

// ── Step ────────────────────────────────────────────────────────────────────

class Step
{
    private string $name;
    private ?string $text = null;
    private ?string $stepCriteria = null;

    /** @var string|array|null */
    private $functions = null;

    private ?array $validSteps = null;
    private ?array $validContexts = null;

    /** @var array<int, array<string, mixed>> */
    private array $sections = [];

    private ?GatherInfo $gatherInfo = null;

    // Step behavior flags
    private bool $end = false;
    private bool $skipUserTurn = false;
    private bool $skipToNextStep = false;

    // Reset object
    private ?string $resetSystemPrompt = null;
    private ?string $resetUserPrompt = null;
    private bool $resetConsolidate = false;
    private bool $resetFullReset = false;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the step's prompt text directly. Mutually exclusive with POM sections.
     */
    public function setText(string $text): self
    {
        if (!empty($this->sections)) {
            throw new \LogicException(
                "Cannot use setText() when POM sections have been added. Use one approach or the other."
            );
        }
        $this->text = $text;
        return $this;
    }

    /**
     * Add a POM section with a body paragraph.
     */
    public function addSection(string $title, string $body): self
    {
        if ($this->text !== null) {
            throw new \LogicException(
                "Cannot add POM sections when setText() has been used. Use one approach or the other."
            );
        }
        $this->sections[] = ['title' => $title, 'body' => $body];
        return $this;
    }

    /**
     * Add a POM section with bullet points.
     */
    public function addBullets(string $title, array $bullets): self
    {
        if ($this->text !== null) {
            throw new \LogicException(
                "Cannot add POM sections when setText() has been used. Use one approach or the other."
            );
        }
        $this->sections[] = ['title' => $title, 'bullets' => $bullets];
        return $this;
    }

    /**
     * Clear all content (both text and sections).
     */
    public function clearSections(): self
    {
        $this->sections = [];
        $this->text = null;
        return $this;
    }

    public function setStepCriteria(string $criteria): self
    {
        $this->stepCriteria = $criteria;
        return $this;
    }

    /**
     * Set which non-internal functions are callable while this step is
     * active.
     *
     * IMPORTANT — inheritance behavior:
     *   If you do NOT call this method, the step inherits whichever
     *   function set was active on the previous step (or the previous
     *   context's last step). The server-side runtime only resets the
     *   active set when a step explicitly declares its `functions`
     *   field. This is the most common source of bugs in multi-step
     *   agents: forgetting setFunctions() on a later step lets the
     *   previous step's tools leak through. Best practice is to call
     *   setFunctions() explicitly on every step that should differ
     *   from the previous one.
     *
     * Keep the per-step active set small: LLM tool selection accuracy
     * degrades noticeably past ~7-8 simultaneously-active tools per
     * call. Use per-step whitelisting to partition large tool
     * collections.
     *
     * Internal functions (e.g. gather_submit, hangup hook) are ALWAYS
     * protected and cannot be deactivated by this whitelist. The
     * native navigation tools next_step and change_context are
     * injected automatically when setValidSteps / setValidContexts is
     * used; they are not affected by this list and do not need to
     * appear in it.
     *
     * @param string|array $functions One of:
     *   - array<string> — whitelist of function names allowed in this step
     *   - []            — explicit disable-all
     *   - 'none'        — synonym for []
     */
    public function setFunctions($functions): self
    {
        $this->functions = $functions;
        return $this;
    }

    public function setValidSteps(array $steps): self
    {
        $this->validSteps = $steps;
        return $this;
    }

    public function setValidContexts(array $contexts): self
    {
        $this->validContexts = $contexts;
        return $this;
    }

    /**
     * Mark this step as terminal for the step flow.
     *
     * IMPORTANT: $end = true does NOT end the conversation or hang up
     * the call. It exits step mode entirely after this step executes
     * — clearing the steps list, current step index, valid_steps, and
     * valid_contexts. The agent keeps running, but operates only
     * under the base system prompt and the context-level prompt; no
     * more step instructions are injected and no more next_step tool
     * is offered.
     *
     * To actually end the call, call a hangup tool or define a
     * hangup hook.
     */
    public function setEnd(bool $end): self
    {
        $this->end = $end;
        return $this;
    }

    public function setSkipUserTurn(bool $skip): self
    {
        $this->skipUserTurn = $skip;
        return $this;
    }

    public function setSkipToNextStep(bool $skip): self
    {
        $this->skipToNextStep = $skip;
        return $this;
    }

    /**
     * Initialize the gather_info configuration for this step.
     */
    public function setGatherInfo(array $opts): self
    {
        $this->gatherInfo = new GatherInfo(
            $opts['output_key'] ?? null,
            $opts['completion_action'] ?? null,
            $opts['prompt'] ?? null
        );
        return $this;
    }

    /**
     * Add a question to this step's gather_info. Initializes
     * gather_info if not yet set.
     *
     * IMPORTANT — gather mode locks function access:
     *   While the model is asking gather questions, the runtime
     *   forcibly deactivates ALL of the step's other functions. The
     *   only callable tools during a gather question are:
     *
     *     - gather_submit (the native answer-submission tool)
     *     - Whatever names you pass in this question's 'functions'
     *       option
     *
     *   next_step and change_context are also filtered out — the
     *   model cannot navigate away until the gather completes. This
     *   is by design: it forces a tight ask → submit → next-question
     *   loop.
     *
     *   If a question needs to call out to a tool (e.g. validate an
     *   email, geocode a ZIP), list that tool name in this question's
     *   'functions' option. Functions listed here are active ONLY for
     *   this question.
     */
    public function addGatherQuestion(array $opts): self
    {
        if ($this->gatherInfo === null) {
            $this->gatherInfo = new GatherInfo();
        }
        $this->gatherInfo->addQuestion($opts);
        return $this;
    }

    public function setResetSystemPrompt(string $systemPrompt): self
    {
        $this->resetSystemPrompt = $systemPrompt;
        return $this;
    }

    public function setResetUserPrompt(string $userPrompt): self
    {
        $this->resetUserPrompt = $userPrompt;
        return $this;
    }

    public function setResetConsolidate(bool $consolidate): self
    {
        $this->resetConsolidate = $consolidate;
        return $this;
    }

    public function setResetFullReset(bool $fullReset): self
    {
        $this->resetFullReset = $fullReset;
        return $this;
    }

    // ── Package-level accessors for validation ──────────────────────────

    public function getValidSteps(): ?array
    {
        return $this->validSteps;
    }

    public function getValidContexts(): ?array
    {
        return $this->validContexts;
    }

    public function getGatherInfo(): ?GatherInfo
    {
        return $this->gatherInfo;
    }

    // ── Rendering ───────────────────────────────────────────────────────

    /**
     * Render the step's prompt text from either raw text or POM sections.
     */
    private function renderText(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        if (empty($this->sections)) {
            throw new \LogicException("Step '{$this->name}' has no text or POM sections defined");
        }

        $parts = [];
        foreach ($this->sections as $section) {
            $title = $section['title'];
            $lines = "## {$title}\n";

            if (isset($section['bullets'])) {
                foreach ($section['bullets'] as $bullet) {
                    $lines .= "- {$bullet}\n";
                }
            } else {
                $lines .= $section['body'] . "\n";
            }

            $parts[] = $lines;
        }

        return rtrim(implode("\n", $parts));
    }

    public function toArray(): array
    {
        $map = [
            'name' => $this->name,
            'text' => $this->renderText(),
        ];

        if ($this->stepCriteria !== null) {
            $map['step_criteria'] = $this->stepCriteria;
        }
        if ($this->functions !== null) {
            $map['functions'] = $this->functions;
        }
        if ($this->validSteps !== null) {
            $map['valid_steps'] = $this->validSteps;
        }
        if ($this->validContexts !== null) {
            $map['valid_contexts'] = $this->validContexts;
        }
        if ($this->end) {
            $map['end'] = true;
        }
        if ($this->skipUserTurn) {
            $map['skip_user_turn'] = true;
        }
        if ($this->skipToNextStep) {
            $map['skip_to_next_step'] = true;
        }

        // Reset object
        $resetObj = [];
        if ($this->resetSystemPrompt !== null) {
            $resetObj['system_prompt'] = $this->resetSystemPrompt;
        }
        if ($this->resetUserPrompt !== null) {
            $resetObj['user_prompt'] = $this->resetUserPrompt;
        }
        if ($this->resetConsolidate) {
            $resetObj['consolidate'] = true;
        }
        if ($this->resetFullReset) {
            $resetObj['full_reset'] = true;
        }
        if (!empty($resetObj)) {
            $map['reset'] = $resetObj;
        }

        if ($this->gatherInfo !== null) {
            $map['gather_info'] = $this->gatherInfo->toArray();
        }

        return $map;
    }
}

// ── Context ─────────────────────────────────────────────────────────────────

class Context
{
    private const MAX_STEPS_PER_CONTEXT = 100;

    private string $name;

    /** @var array<string, Step> */
    private array $steps = [];

    /** @var string[] */
    private array $stepOrder = [];

    private ?array $validContexts = null;
    private ?array $validSteps = null;

    // Context entry parameters
    private ?string $postPrompt = null;
    private ?string $systemPrompt = null;
    private bool $consolidate = false;
    private bool $fullReset = false;
    private ?string $userPrompt = null;
    private bool $isolated = false;

    // Context prompt (plain text or POM)
    private ?string $promptText = null;

    /** @var array<int, array<string, mixed>> */
    private array $promptSections = [];

    // System prompt (plain text or POM)
    /** @var array<int, array<string, mixed>> */
    private array $systemPromptSections = [];

    // Fillers
    /** @var array<string, string[]>|null */
    private ?array $enterFillers = null;

    /** @var array<string, string[]>|null */
    private ?array $exitFillers = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    // ── Steps ───────────────────────────────────────────────────────────

    /**
     * Add a new step to this context and return it for further configuration.
     */
    public function addStep(string $name, array $opts = []): Step
    {
        if (isset($this->steps[$name])) {
            throw new \LogicException("Step '{$name}' already exists in context '{$this->name}'");
        }
        if (count($this->steps) >= self::MAX_STEPS_PER_CONTEXT) {
            throw new \LogicException(
                "Maximum steps per context (" . self::MAX_STEPS_PER_CONTEXT . ") exceeded"
            );
        }

        $step = new Step($name);
        $this->steps[$name] = $step;
        $this->stepOrder[] = $name;

        // Apply shorthand options if provided
        if (isset($opts['text'])) {
            $step->setText($opts['text']);
        }
        if (isset($opts['step_criteria'])) {
            $step->setStepCriteria($opts['step_criteria']);
        }
        if (isset($opts['functions'])) {
            $step->setFunctions($opts['functions']);
        }
        if (isset($opts['valid_steps'])) {
            $step->setValidSteps($opts['valid_steps']);
        }
        if (isset($opts['valid_contexts'])) {
            $step->setValidContexts($opts['valid_contexts']);
        }

        return $step;
    }

    public function getStep(string $name): ?Step
    {
        return $this->steps[$name] ?? null;
    }

    public function removeStep(string $name): self
    {
        if (isset($this->steps[$name])) {
            unset($this->steps[$name]);
            $this->stepOrder = array_values(array_filter(
                $this->stepOrder,
                fn(string $n) => $n !== $name
            ));
        }
        return $this;
    }

    public function moveStep(string $name, int $position): self
    {
        if (!isset($this->steps[$name])) {
            throw new \LogicException("Step '{$name}' not found in context '{$this->name}'");
        }

        $this->stepOrder = array_values(array_filter(
            $this->stepOrder,
            fn(string $n) => $n !== $name
        ));
        array_splice($this->stepOrder, $position, 0, [$name]);

        return $this;
    }

    // ── Context prompt (POM) ────────────────────────────────────────────

    public function setPrompt(string $prompt): self
    {
        if (!empty($this->promptSections)) {
            throw new \LogicException(
                "Cannot use setPrompt() when POM sections have been added."
            );
        }
        $this->promptText = $prompt;
        return $this;
    }

    public function addSection(string $title, string $body): self
    {
        if ($this->promptText !== null) {
            throw new \LogicException(
                "Cannot add POM sections when setPrompt() has been used."
            );
        }
        $this->promptSections[] = ['title' => $title, 'body' => $body];
        return $this;
    }

    public function addBullets(string $title, array $bullets): self
    {
        if ($this->promptText !== null) {
            throw new \LogicException(
                "Cannot add POM sections when setPrompt() has been used."
            );
        }
        $this->promptSections[] = ['title' => $title, 'bullets' => $bullets];
        return $this;
    }

    // ── System prompt (POM) ─────────────────────────────────────────────

    public function setSystemPrompt(string $systemPrompt): self
    {
        if (!empty($this->systemPromptSections)) {
            throw new \LogicException(
                "Cannot use setSystemPrompt() when POM sections have been added."
            );
        }
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    public function addSystemSection(string $title, string $body): self
    {
        if ($this->systemPrompt !== null) {
            throw new \LogicException(
                "Cannot add POM sections when setSystemPrompt() has been used."
            );
        }
        $this->systemPromptSections[] = ['title' => $title, 'body' => $body];
        return $this;
    }

    public function addSystemBullets(string $title, array $bullets): self
    {
        if ($this->systemPrompt !== null) {
            throw new \LogicException(
                "Cannot add POM sections when setSystemPrompt() has been used."
            );
        }
        $this->systemPromptSections[] = ['title' => $title, 'bullets' => $bullets];
        return $this;
    }

    // ── Config setters ──────────────────────────────────────────────────

    public function setValidContexts(array $contexts): self
    {
        $this->validContexts = $contexts;
        return $this;
    }

    public function setValidSteps(array $steps): self
    {
        $this->validSteps = $steps;
        return $this;
    }

    public function setPostPrompt(string $postPrompt): self
    {
        $this->postPrompt = $postPrompt;
        return $this;
    }

    public function setConsolidate(bool $consolidate): self
    {
        $this->consolidate = $consolidate;
        return $this;
    }

    public function setFullReset(bool $fullReset): self
    {
        $this->fullReset = $fullReset;
        return $this;
    }

    public function setUserPrompt(string $userPrompt): self
    {
        $this->userPrompt = $userPrompt;
        return $this;
    }

    /**
     * Mark this context as isolated — entering it wipes conversation
     * history.
     *
     * When $isolated = true and the context is entered via
     * change_context, the runtime wipes the conversation array. The
     * model starts fresh with only the new context's system_prompt +
     * step instructions, with no memory of prior turns.
     *
     * EXCEPTION — reset overrides the wipe:
     *   If the context also has a reset configuration (via
     *   setConsolidate or setFullReset), the wipe is skipped in
     *   favor of the reset behavior. Use reset with consolidate=true
     *   to summarize prior history into a single message instead of
     *   dropping it entirely.
     *
     * Use cases: switching to a sensitive billing flow that should
     * not see prior small-talk; handing off to a different agent
     * persona; resetting after a long off-topic detour.
     */
    public function setIsolated(bool $isolated): self
    {
        $this->isolated = $isolated;
        return $this;
    }

    // ── Fillers ─────────────────────────────────────────────────────────

    public function setEnterFillers(array $fillers): self
    {
        $this->enterFillers = $fillers;
        return $this;
    }

    public function setExitFillers(array $fillers): self
    {
        $this->exitFillers = $fillers;
        return $this;
    }

    public function addEnterFiller(string $lang, string $text): self
    {
        if ($this->enterFillers === null) {
            $this->enterFillers = [];
        }
        if (!isset($this->enterFillers[$lang])) {
            $this->enterFillers[$lang] = [];
        }
        $this->enterFillers[$lang][] = $text;
        return $this;
    }

    public function addExitFiller(string $lang, string $text): self
    {
        if ($this->exitFillers === null) {
            $this->exitFillers = [];
        }
        if (!isset($this->exitFillers[$lang])) {
            $this->exitFillers[$lang] = [];
        }
        $this->exitFillers[$lang][] = $text;
        return $this;
    }

    // ── Internal accessors for validation ───────────────────────────────

    /**
     * @return array<string, Step>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @return string[]
     */
    public function getStepOrder(): array
    {
        return $this->stepOrder;
    }

    public function getValidContexts(): ?array
    {
        return $this->validContexts;
    }

    // ── Rendering helpers ───────────────────────────────────────────────

    /**
     * Render POM sections to markdown text.
     */
    private function renderSections(array $sections): string
    {
        $parts = [];
        foreach ($sections as $section) {
            $title = $section['title'];
            $lines = "## {$title}\n";

            if (isset($section['bullets'])) {
                foreach ($section['bullets'] as $bullet) {
                    $lines .= "- {$bullet}\n";
                }
            } else {
                $lines .= $section['body'] . "\n";
            }

            $parts[] = $lines;
        }

        return rtrim(implode("\n", $parts));
    }

    // ── Serialization ───────────────────────────────────────────────────

    public function toArray(): array
    {
        $map = [];

        // Steps in order
        $stepMaps = [];
        foreach ($this->stepOrder as $stepName) {
            $stepMaps[] = $this->steps[$stepName]->toArray();
        }
        $map['steps'] = $stepMaps;

        if ($this->validContexts !== null) {
            $map['valid_contexts'] = $this->validContexts;
        }
        if ($this->validSteps !== null) {
            $map['valid_steps'] = $this->validSteps;
        }
        if ($this->postPrompt !== null) {
            $map['post_prompt'] = $this->postPrompt;
        }

        // System prompt: POM sections take priority
        if (!empty($this->systemPromptSections)) {
            $map['system_prompt'] = $this->renderSections($this->systemPromptSections);
        } elseif ($this->systemPrompt !== null) {
            $map['system_prompt'] = $this->systemPrompt;
        }

        if ($this->consolidate) {
            $map['consolidate'] = true;
        }
        if ($this->fullReset) {
            $map['full_reset'] = true;
        }
        if ($this->userPrompt !== null) {
            $map['user_prompt'] = $this->userPrompt;
        }
        if ($this->isolated) {
            $map['isolated'] = true;
        }

        // Context prompt: POM sections take priority
        if (!empty($this->promptSections)) {
            $map['prompt'] = $this->renderSections($this->promptSections);
        } elseif ($this->promptText !== null) {
            $map['prompt'] = $this->promptText;
        }

        if ($this->enterFillers !== null) {
            $map['enter_fillers'] = $this->enterFillers;
        }
        if ($this->exitFillers !== null) {
            $map['exit_fillers'] = $this->exitFillers;
        }

        return $map;
    }
}

// ── ContextBuilder ──────────────────────────────────────────────────────────

/**
 * Builder for multi-step, multi-context AI agent workflows.
 *
 * A ContextBuilder owns one or more Contexts; each Context owns an
 * ordered list of Steps. Only one context and one step is active at a
 * time. Per chat turn, the runtime injects the current step's
 * instructions as a system message, then asks the LLM for a response.
 *
 * ## Native tools auto-injected by the runtime
 *
 * When a step (or its enclosing context) declares valid_steps or
 * valid_contexts, the runtime auto-injects two native tools so the
 * model can navigate the flow:
 *
 *   - next_step(step: enum)         — present when valid_steps is set
 *   - change_context(context: enum) — present when valid_contexts is set
 *
 * A third native tool — gather_submit — is injected during gather_info
 * questioning. These three names are reserved: validate() rejects any
 * agent that defines a SWAIG tool with one of them. See the
 * RESERVED_NATIVE_TOOL_NAMES constant.
 *
 * ## Function whitelisting (Step::setFunctions)
 *
 * Each step may declare a functions whitelist. The whitelist is
 * applied in-memory at the start of each LLM turn. CRITICALLY: if a
 * step does NOT declare a functions field, it INHERITS the previous
 * step's active set. See Step::setFunctions for details and examples.
 */
class ContextBuilder
{
    private const MAX_CONTEXTS = 50;

    /** @var array<string, Context> */
    private array $contexts = [];

    /** @var string[] */
    private array $contextOrder = [];

    /**
     * Optional callable that returns the list of registered SWAIG
     * tool names, used by validate() to check for collisions with
     * reserved native tool names. AgentBase::defineContexts() wires
     * this up automatically.
     * @var callable|null
     */
    private $toolNameSupplier = null;

    /**
     * Attach a callable that returns registered SWAIG tool names so
     * validate() can check them against RESERVED_NATIVE_TOOL_NAMES.
     * Called internally by AgentBase::defineContexts().
     *
     * @param callable $supplier () => array<string>
     */
    public function attachToolNameSupplier(callable $supplier): self
    {
        $this->toolNameSupplier = $supplier;
        return $this;
    }

    /**
     * Add a new context and return it for further configuration.
     */
    public function addContext(string $name): Context
    {
        if (isset($this->contexts[$name])) {
            throw new \LogicException("Context '{$name}' already exists");
        }
        if (count($this->contexts) >= self::MAX_CONTEXTS) {
            throw new \LogicException(
                "Maximum number of contexts (" . self::MAX_CONTEXTS . ") exceeded"
            );
        }

        $context = new Context($name);
        $this->contexts[$name] = $context;
        $this->contextOrder[] = $name;

        return $context;
    }

    /**
     * Get an existing context by name, or null if not found.
     */
    public function getContext(string $name): ?Context
    {
        return $this->contexts[$name] ?? null;
    }

    public function hasContexts(): bool
    {
        return !empty($this->contexts);
    }

    /**
     * Validate the contexts configuration.
     *
     * @return string[] Array of error strings (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->contexts)) {
            $errors[] = 'At least one context must be defined';
            return $errors;
        }

        // Single context must be named "default"
        if (count($this->contexts) === 1) {
            $contextName = array_key_first($this->contexts);
            if ($contextName !== 'default') {
                $errors[] = "When using a single context, it must be named 'default'";
            }
        }

        // Each context must have at least one step
        foreach ($this->contexts as $contextName => $context) {
            if (empty($context->getSteps())) {
                $errors[] = "Context '{$contextName}' must have at least one step";
            }
        }

        // Validate step references in valid_steps
        foreach ($this->contexts as $contextName => $context) {
            foreach ($context->getSteps() as $stepName => $step) {
                if ($step->getValidSteps() !== null) {
                    foreach ($step->getValidSteps() as $validStep) {
                        if ($validStep !== 'next' && !isset($context->getSteps()[$validStep])) {
                            $errors[] = "Step '{$stepName}' in context '{$contextName}'"
                                . " references unknown step '{$validStep}'";
                        }
                    }
                }
            }
        }

        // Validate context references in valid_contexts (context-level)
        foreach ($this->contexts as $contextName => $context) {
            if ($context->getValidContexts() !== null) {
                foreach ($context->getValidContexts() as $validCtx) {
                    if (!isset($this->contexts[$validCtx])) {
                        $errors[] = "Context '{$contextName}' references unknown context '{$validCtx}'";
                    }
                }
            }
        }

        // Validate context references in valid_contexts (step-level)
        foreach ($this->contexts as $contextName => $context) {
            foreach ($context->getSteps() as $stepName => $step) {
                if ($step->getValidContexts() !== null) {
                    foreach ($step->getValidContexts() as $validCtx) {
                        if (!isset($this->contexts[$validCtx])) {
                            $errors[] = "Step '{$stepName}' in context '{$contextName}'"
                                . " references unknown context '{$validCtx}'";
                        }
                    }
                }
            }
        }

        // Validate gather_info configurations
        foreach ($this->contexts as $contextName => $context) {
            foreach ($context->getSteps() as $stepName => $step) {
                $gi = $step->getGatherInfo();
                if ($gi !== null) {
                    if (empty($gi->getQuestions())) {
                        $errors[] = "Step '{$stepName}' in context '{$contextName}'"
                            . " has gather_info with no questions";
                    }

                    // Check for duplicate keys
                    $seenKeys = [];
                    foreach ($gi->getQuestions() as $q) {
                        if (isset($seenKeys[$q->getKey()])) {
                            $errors[] = "Step '{$stepName}' in context '{$contextName}'"
                                . " has duplicate gather_info question key '{$q->getKey()}'";
                        }
                        $seenKeys[$q->getKey()] = true;
                    }

                    // Validate completion_action
                    $action = $gi->getCompletionAction();
                    if ($action !== null) {
                        if ($action === 'next_step') {
                            $stepIdx = array_search($stepName, $context->getStepOrder(), true);
                            if ($stepIdx >= count($context->getStepOrder()) - 1) {
                                $errors[] = "Step '{$stepName}' in context '{$contextName}'"
                                    . " has gather_info completion_action='next_step'"
                                    . " but it is the last step in the context."
                                    . " Either (1) add another step after '{$stepName}',"
                                    . " (2) set completion_action to the name of an"
                                    . " existing step in this context to jump to it, or"
                                    . " (3) set completion_action=null (default) to stay"
                                    . " in '{$stepName}' after gathering completes.";
                            }
                        } elseif (!isset($context->getSteps()[$action])) {
                            $available = array_keys($context->getSteps());
                            sort($available);
                            $availableStr = "['" . implode("', '", $available) . "']";
                            $errors[] = "Step '{$stepName}' in context '{$contextName}'"
                                . " has gather_info completion_action='{$action}'"
                                . " but '{$action}' is not a step in this context."
                                . " Valid options: 'next_step' (advance to the next"
                                . " sequential step), null (stay in the current step),"
                                . " or one of {$availableStr}.";
                        }
                    }
                }
            }
        }

        // Validate that user-defined tools do not collide with reserved
        // native tool names. The runtime auto-injects next_step /
        // change_context / gather_submit when contexts/steps are
        // present, so user tools sharing those names would never be
        // called.
        if ($this->toolNameSupplier !== null) {
            $registered = ($this->toolNameSupplier)();
            if (is_array($registered)) {
                $colliding = array_values(array_unique(array_intersect(
                    $registered,
                    RESERVED_NATIVE_TOOL_NAMES
                )));
                sort($colliding);
                if (!empty($colliding)) {
                    $collidingStr = "['" . implode("', '", $colliding) . "']";
                    $reserved = RESERVED_NATIVE_TOOL_NAMES;
                    sort($reserved);
                    $reservedStr = "['" . implode("', '", $reserved) . "']";
                    $errors[] = "Tool name(s) {$collidingStr} collide with reserved"
                        . " native tools auto-injected by contexts/steps. The names"
                        . " {$reservedStr} are reserved and cannot be used for"
                        . " user-defined SWAIG tools when contexts/steps are in use."
                        . " Rename your tool(s) to avoid the collision.";
                }
            }
        }

        return $errors;
    }

    /**
     * Serialize all contexts in order. Validates before converting.
     */
    public function toArray(): array
    {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new \LogicException('Validation failed: ' . implode('; ', $errors));
        }

        $result = [];
        foreach ($this->contextOrder as $name) {
            $result[$name] = $this->contexts[$name]->toArray();
        }

        return $result;
    }

    // ── Factory ─────────────────────────────────────────────────────────

    /**
     * Create a builder pre-populated with a single named context.
     */
    public static function createSimpleContext(string $name): self
    {
        $builder = new self();
        $builder->addContext($name);
        return $builder;
    }
}
