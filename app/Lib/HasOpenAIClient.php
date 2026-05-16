<?php

namespace App\Lib;

use OpenAI;
use OpenAI\Client;

trait HasOpenAIClient
{

    private Client $client;

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        if (!isset($this->client)) {
            $this->client = OpenAI::client(config('services.openai.secret'));
        }
        return $this->client;
    }
}
