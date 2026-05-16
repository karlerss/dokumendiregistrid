<?php

namespace App\Lib\Parser;

use App\Models\File;
use Symfony\Component\DomCrawler\Crawler;

abstract class BaseParser
{
    public function __construct(public string $path)
    {
    }

    /**
     * @return File[]
     */
    abstract public function parse(?int $parentId = null): array;

    /**
     * @param false|string $msgHtml
     * @return false|string
     */
    protected function extractBody(false|string $msgHtml, ?string $forceEncoding = null): string|false
    {
        $c = new Crawler();

        if (!$forceEncoding) {
            $matched = preg_match('/charset=(.*?)[";]/', $msgHtml, $matches);
            if ($matched) {
                $enc = $matches[1];
            } else {
                $enc = mb_detect_encoding($msgHtml);
            }

            $c->addHtmlContent($msgHtml, $enc);
        } else {
            $c->addHtmlContent($msgHtml, $forceEncoding);
        }

        if ($c->filter('body')->count() > 0) {
            $msgHtml = $c->filter('body')->html();
        }

        return $msgHtml;
    }
}
