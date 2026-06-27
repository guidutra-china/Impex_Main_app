<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\ClaudeAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaudeAssistantLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_tool_call_is_refused_and_fed_back(): void
    {
        $user = User::factory()->create(); // sem nenhuma permissão

        $assistant = new class extends ClaudeAssistant
        {
            public array $secondCallMessages = [];

            private int $calls = 0;

            protected function callModel(array $messages, array $toolDefs): object
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return (object) [
                        'stopReason' => 'tool_use',
                        'content' => [
                            (object) [
                                'type' => 'tool_use',
                                'id' => 't1',
                                'name' => 'buscar_empresas',
                                'input' => ['q' => 'x'],
                            ],
                        ],
                    ];
                }

                $this->secondCallMessages = $messages;

                return (object) [
                    'stopReason' => 'end_turn',
                    'content' => [
                        (object) ['type' => 'text', 'text' => 'feito'],
                    ],
                ];
            }
        };

        $reply = $assistant->ask(
            [['role' => 'user', 'content' => 'buscar empresa x']],
            $user,
        );

        $this->assertSame('feito', $reply);

        $lastTurn = end($assistant->secondCallMessages);
        $content = json_encode($lastTurn, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(__('assistant.tool_denied'), $content);
    }
}
