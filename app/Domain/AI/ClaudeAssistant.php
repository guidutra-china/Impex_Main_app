<?php

declare(strict_types=1);

namespace App\Domain\AI;

use Anthropic\Client;
use Anthropic\Messages\ThinkingConfigAdaptive;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolResultBlockParam;
use App\Domain\AI\Contracts\AssistantTool;
use App\Domain\AI\Tools\OpenFinancialItemsTool;
use App\Domain\AI\Tools\ProductionStatusTool;
use App\Domain\AI\Tools\SearchCompaniesTool;
use App\Domain\AI\Tools\SearchInquiriesTool;
use App\Domain\AI\Tools\SearchProformaInvoicesTool;
use App\Domain\AI\Tools\SearchPurchaseOrdersTool;
use App\Domain\AI\Tools\SearchQuotationsTool;
use App\Domain\AI\Tools\SearchShipmentsTool;
use App\Models\User;

/**
 * Drives one assistant turn through the Claude agentic loop: the model may call
 * permission-aware tools, whose results are fed back until it produces a final
 * text answer. Every tool call is authorized against the current user and logged.
 */
class ClaudeAssistant
{
    /** Hard cap on tool round-trips per turn (loop safety). */
    private const MAX_STEPS = 8;

    /** @var array<string,AssistantTool> */
    protected array $tools = [];

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));

        $this->register(new SearchCompaniesTool);
        $this->register(new SearchInquiriesTool);
        $this->register(new SearchQuotationsTool);
        $this->register(new SearchProformaInvoicesTool);
        $this->register(new SearchPurchaseOrdersTool);
        $this->register(new SearchShipmentsTool);
        $this->register(new ProductionStatusTool);
        $this->register(new OpenFinancialItemsTool);
    }

    public function register(AssistantTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Run the loop for the current conversation and return the assistant's reply text.
     *
     * @param  list<array{role:string,content:mixed}>  $messages  conversation in API shape
     *                                                            (prior turns are text-only)
     */
    public function ask(array $messages, User $user): string
    {
        $toolDefs = array_map(
            fn (AssistantTool $tool) => Tool::with(
                inputSchema: $tool->schema(),
                name: $tool->name(),
                description: $tool->description(),
            ),
            array_values($this->tools),
        );

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = $this->callModel($messages, $toolDefs);

            // Preserve the full assistant turn (text + thinking + tool_use blocks).
            $messages[] = ['role' => 'assistant', 'content' => $response->content];

            if ($response->stopReason !== 'tool_use') {
                return $this->extractText($response->content);
            }

            $toolResults = [];
            foreach ($response->content as $block) {
                if (($block->type ?? null) === 'tool_use') {
                    $toolResults[] = $this->runTool($block, $user);
                }
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return __('assistant.too_many_steps');
    }

    /**
     * Single round-trip to the model. Extracted so the agentic loop can be tested
     * without hitting the real API (override in a test double).
     *
     * @param  list<array{role:string,content:mixed}>  $messages
     * @param  list<Tool>  $toolDefs
     */
    protected function callModel(array $messages, array $toolDefs): object
    {
        return $this->client->messages->create(
            maxTokens: 8192,
            messages: $messages,
            model: $this->model(),
            system: $this->systemPrompt(),
            thinking: ThinkingConfigAdaptive::with(),
            tools: $toolDefs,
            // 'medium' balances quality vs cost/latency for routine internal queries.
            outputConfig: ['effort' => 'medium'],
        );
    }

    protected function model(): string
    {
        return (string) config('services.anthropic.assistant_model', 'claude-opus-4-8');
    }

    /**
     * Execute one tool_use block, enforcing permission and logging the call.
     */
    protected function runTool(object $block, User $user): ToolResultBlockParam
    {
        $tool = $this->tools[$block->name] ?? null;

        if ($tool === null) {
            return ToolResultBlockParam::with(
                toolUseID: $block->id,
                content: __('assistant.tool_unknown'),
                isError: true,
            );
        }

        if (! $tool->authorize($user)) {
            return ToolResultBlockParam::with(
                toolUseID: $block->id,
                content: __('assistant.tool_denied'),
                isError: true,
            );
        }

        try {
            $output = $tool->run((array) $block->input, $user);

            activity('ai-assistant')
                ->causedBy($user)
                ->withProperties(['tool' => $tool->name(), 'input' => $block->input])
                ->log('assistant_tool_call');

            return ToolResultBlockParam::with(
                toolUseID: $block->id,
                content: (string) json_encode($output, JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            report($e);

            return ToolResultBlockParam::with(
                toolUseID: $block->id,
                content: __('assistant.tool_error', ['error' => $e->getMessage()]),
                isError: true,
            );
        }
    }

    /**
     * @param  list<object>  $content
     */
    protected function extractText(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if (($block->type ?? null) === 'text') {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n\n", $parts)) ?: __('assistant.no_answer');
    }

    protected function systemPrompt(): string
    {
        $language = $this->userLanguage();

        return <<<PROMPT
        You are the internal assistant for Impex, a Brazil–China trading company based in Shenzhen.
        Your job is to help the team look up system data and work faster.

        Rules:
        - Always reply in the same language the user writes in. If it is unclear, default to {$language}.
        - Use the available tools to fetch real data before answering; never invent data, IDs, statuses or company names.
        - If a tool returns a permission error, explain that the user does not have access to that information.
        - Be concise and direct. Present result lists in an organized way.
        PROMPT;
    }

    private function userLanguage(): string
    {
        return match (app()->getLocale()) {
            'pt_BR' => 'Brazilian Portuguese',
            'zh_CN' => 'Simplified Chinese',
            default => 'English',
        };
    }
}
