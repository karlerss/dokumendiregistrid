<?php

namespace Tests\Feature;

use App\Lib\Fetcher\AdrFetcher;
use App\Lib\Parser\AsiceParser;
use App\Lib\Parser\DirParser;
use App\Lib\Parser\EmlParser;
use App\Lib\Parser\MsgParser;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use App\Models\Topic;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ParserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function dir_parser_asice(): void
    {
        (new DirParser(base_path('tests/__fixtures/dir1')))->parse();

        $files = File::query()->get();

        $this->assertEquals(4, $files->count());

        $this->assertEquals('8-22262 20.03.2024 Väljaminev kiri.asice', $files[0]->name);
        $this->assertEquals('Vabariigi valitsuse määruse Tervisekassa tervishoiuteenuste loetelu eelnõu kooskõlastamine.pdf', $files[1]->name);
        $this->assertEquals('with-pdf.eml', $files[2]->name);
        $this->assertEquals('Order_ID_7B620ED2-0003.pdf', $files[3]->name);
    }

    /**
     * @test
     */
    public function it_parses_eml_with_weird_encoding()
    {
        (new EmlParser(base_path('tests/__fixtures/ekiri.eml')))->parse();

        $file = File::query()->first();

        $this->assertStringContainsString('Tähelepanu', $file->html);
    }

    /**
     * @test
     */
    public function it_parses_msg_with_weird_encoding()
    {
        (new MsgParser(base_path('tests/__fixtures/polva.msg')))->parse();

        $file = File::query()->first();

        $this->assertStringContainsString('Põlvamaa', $file->html);
    }

    /**
     * @test
     */
    public function it_parses_msg2_with_weird_encoding()
    {
        (new MsgParser(base_path('tests/__fixtures/hambad.msg')))->parse();

        $file = File::query()->first();

        $this->assertStringContainsString('Pärg', $file->html);
    }

    /**
     * @test
     */
    public function asice_parser_2(): void
    {
        $r = (new AsiceParser(base_path('tests/__fixtures/50k.asice')))->parse();

        $this->assertEquals(2, count($r));
        $this->assertEquals('50k.asice', $r[0]->name);
        $this->assertEquals('50k.pdf', $r[1]->name);
        $this->assertEquals($r[0]->id, $r[1]->parent_id);
    }

    /**
     * @test
     */
    public function msg_parse()
    {
        $res = (new MsgParser(base_path('tests/__fixtures/Vastus2.msg')))->parse();
        $this->assertEquals(4, count($res));
        $c = collect($res);

        $this->assertEquals(1, $c->where('parent_id', null)->count());
        $this->assertEquals(3, $c->where('parent_id', $c->first()->id)->count());
    }

    public function test_parse_old_bdoc_like_asice()
    {
        $r = (new AsiceParser(base_path('tests/__fixtures/old_bdoc.bdoc')))->parse();

        $this->assertEquals(2, count($r));

        $this->assertEquals('718-000650-1.pdf', $r[1]->name);
    }
}
