<?php

namespace Tests\Unit;

use App\Lib\Parser\RtfParser;
use PHPUnit\Framework\TestCase;

class RtfParserTest extends TestCase
{
    public function test_unknown_font_charset_falls_back_instead_of_failing(): void
    {
        $rtf = '{\rtf1\ansi\deff0{\fonttbl{\f0\fcharset88 Times;}}\f0 Tere maailm\par}';

        $html = @RtfParser::toHtml($rtf);

        $this->assertStringContainsString('Tere maailm', $html);
    }
}
