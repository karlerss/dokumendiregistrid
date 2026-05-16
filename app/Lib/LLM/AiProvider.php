<?php

namespace App\Lib\LLM;

interface AiProvider
{
    public function getJson(string $systemPrompt, string $userPrompt, array $jsonSchema): array;
}
