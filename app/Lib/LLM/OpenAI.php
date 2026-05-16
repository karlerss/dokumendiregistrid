<?php

namespace App\Lib\LLM;

use App\Lib\HasOpenAIClient;

class OpenAI implements AiProvider
{
    use HasOpenAIClient;

    public function __construct()
    {
    }

    public function getJson(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $res = $this->getClient()->chat()->create([
            'model' => 'gpt-4o',
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

        return json_decode($res->choices[0]->message->content, true);
    }
}
