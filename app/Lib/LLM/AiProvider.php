<?php

namespace App\Lib\LLM;

interface AiProvider
{
    public function getJson(string $systemPrompt, string $userPrompt, array $jsonSchema): array;

    /**
     * Same as getJson, but also reports token usage.
     *
     * @return array{data: array, input_tokens: int, output_tokens: int, model: string}
     */
    public function getJsonWithUsage(string $systemPrompt, string $userPrompt, array $jsonSchema): array;
}
