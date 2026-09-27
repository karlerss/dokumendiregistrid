<?php

namespace App\Lib\LLM;

use GuzzleHttp\Client;

class Ollama implements AiProvider
{
    public function __construct(private string $model = 'qwen2.5:14b', private string $baseUri = 'http://localhost:11434')
    {
    }

    public function getJson(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        return $this->getJsonWithUsage($systemPrompt, $userPrompt, $jsonSchema)['data'];
    }

    public function getJsonWithUsage(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $client = new Client([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $res = $client->post('/api/generate', [
            'json' => [
                'model' => $this->model,
                'prompt' => $systemPrompt
                    . PHP_EOL . PHP_EOL . $userPrompt,
                'format' => $jsonSchema,
                'stream' => false,
            ],
        ]);
        $assocRes = json_decode($res->getBody()->getContents(), true);
        $jsonResponse = $assocRes['response'] ?? '';
        $data = json_decode($jsonResponse, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Model returned non-JSON content: ' . mb_substr((string)$jsonResponse, 0, 200));
        }

        return [
            'data' => $data,
            'input_tokens' => (int)($assocRes['prompt_eval_count'] ?? 0),
            'output_tokens' => (int)($assocRes['eval_count'] ?? 0),
            'model' => $this->model,
        ];
    }
}
