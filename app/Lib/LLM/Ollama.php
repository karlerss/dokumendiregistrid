<?php

namespace App\Lib\LLM;

use GuzzleHttp\Client;

class Ollama implements AiProvider
{
    public function __construct()
    {

    }

    public function getJson(string $systemPrompt, string $userPrompt, array $jsonSchema): array
    {
        $client = new Client([
            'base_uri' => 'http://localhost:11434',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $res = $client->post('/api/generate', [
            'json' => [
//                'model' => 'deepseek-r1:14b',
                'model' => 'qwen2.5:14b',
                'prompt' => $systemPrompt
                    . PHP_EOL . PHP_EOL . $userPrompt,
                'format' => $jsonSchema,
                'stream' => false,
            ],
        ]);
        $assocRes = json_decode($res->getBody()->getContents(), true);
        $jsonResponse = $assocRes['response'] ?? [];

        return json_decode($jsonResponse, true);
    }
}
