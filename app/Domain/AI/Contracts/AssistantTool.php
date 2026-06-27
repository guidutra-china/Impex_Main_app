<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Models\User;

/**
 * A tool the AI assistant can call. Every tool is permission-aware: it declares
 * the same authorization the UI uses, so the assistant can never surface data
 * the current user could not see in the panel.
 */
interface AssistantTool
{
    /** Name the model uses to call the tool (snake_case, stable). */
    public function name(): string;

    /** Description that tells the model WHEN to call the tool, not just what it does. */
    public function description(): string;

    /**
     * JSON Schema (draft 2020-12) for the tool input.
     *
     * @return array{type:string,properties?:array<string,mixed>,required?:list<string>}
     */
    public function schema(): array;

    /** Whether $user may use this tool — mirrors the panel permission. */
    public function authorize(User $user): bool;

    /**
     * Execute the tool for $user. The return value is JSON-encoded back to the model.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input, User $user): array;
}
