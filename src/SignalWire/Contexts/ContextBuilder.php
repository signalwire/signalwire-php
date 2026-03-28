<?php

declare(strict_types=1);

namespace SignalWire\Contexts;

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
     * Set which functions are available. Pass 'none' to disable all, or an array of function names.
     *
     * @param string|array $functions
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
     * Add a question to this step's gather_info. Initializes gather_info if not yet set.
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

class ContextBuilder
{
    private const MAX_CONTEXTS = 50;

    /** @var array<string, Context> */
    private array $contexts = [];

    /** @var string[] */
    private array $contextOrder = [];

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
                                    . " but it is the last step";
                            }
                        } elseif (!isset($context->getSteps()[$action])) {
                            $errors[] = "Step '{$stepName}' in context '{$contextName}'"
                                . " has gather_info completion_action='{$action}'"
                                . " but step '{$action}' does not exist";
                        }
                    }
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
