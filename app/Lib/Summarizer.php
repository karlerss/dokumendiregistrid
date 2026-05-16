<?php

namespace App\Lib;

class Summarizer
{
    use HasOpenAIClient;

    public function summarize(string $text)
    {
        $response = $this->getClient()->chat()->create([
            'model' => 'gpt-3.5-turbo-0125',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => <<<PROMPT
                    You are a summarisation service that is used for summarising public government documents.
                    Please summarise the following text.
                    Try to find info with journalistic value.
                    You must give the response in Estonian language.
                    Use the html strong tag to highlight the most important phrases.
                    Be brief and concise.
                    Be specific, do not list topics, but give the most important points.
                    The title must summarize the content.
                    Use html list to list the most important points.
                    Return response in the following format:
                    {
                        "title": "...",
                        "summary_content_html": "..."
                    }
                    PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
        ]);

        return json_decode($response->choices[0]->message->content, true);
    }
}
