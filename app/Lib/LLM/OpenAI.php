<?php

namespace App\Lib\LLM;

use App\Lib\HasOpenAIClient;

class OpenAI implements AiProvider
{
    use HasOpenAIClient;

    public function __construct(private ?string $model = null)
    {
        $this->model ??= config('services.openai.model', 'gpt-5.4');
    }

    public function getJson(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        return $this->getJsonWithUsage($systemPrompt, $userPrompt, $jsonSchema)['data'];
    }

    public function getJsonWithUsage(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $res = $this->getClient()->chat()->create([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'query_response',
                    'strict' => true,
                    'schema' => $jsonSchema,
                ]],
        ]);

        $content = $res->choices[0]->message->content ?? '';
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Model returned non-JSON content: ' . mb_substr($content, 0, 200));
        }

        return [
            'data' => $data,
            'input_tokens' => (int)($res->usage->promptTokens ?? 0),
            'output_tokens' => (int)($res->usage->completionTokens ?? 0),
            'model' => $res->model ?? $this->model,
        ];
    }
}
