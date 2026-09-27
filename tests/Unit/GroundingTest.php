<?php

namespace Tests\Unit;

use App\Lib\Pii\Grounding;
use PHPUnit\Framework\TestCase;

class GroundingTest extends TestCase
{
    public function test_finds_forms_across_surfaces_with_whitespace_collapsed(): void
    {
        $g = new Grounding([
            'body:1' => "Lugupidamisega\n\nMari   Maasikas\nmari.maasikas@example.com",
            'title' => 'Kiri M. M.',
            'filename:1' => 'Leping_M.Maasikas.pdf',
        ]);

        $this->assertSame(['body:1'], $g->find('Mari Maasikas'));
        $this->assertSame(['title'], $g->find('M. M.'));
        $this->assertSame(['filename:1'], $g->find('M.Maasikas'));
        $this->assertSame([], $g->find('mari maasikas'), 'case-sensitive');
        $this->assertSame([], $g->find('Maasikase'), 'inflected form not present is not found');
    }

    public function test_verify_all_splits_and_dedupes(): void
    {
        $g = new Grounding(['body:1' => 'Jaan Tamm ja Jaan Tamme auto']);
        [$verified, $unverified] = $g->verifyAll(['Jaan Tamm', 'Jaan Tamme', 'Jaan Tamm', 'Tammele', '']);
        $this->assertSame(['Jaan Tamm', 'Jaan Tamme'], array_column($verified, 'text'));
        $this->assertSame(['Tammele'], $unverified);
    }

    public function test_short_needles_never_match(): void
    {
        $g = new Grounding(['body:1' => 'A B C']);
        $this->assertSame([], $g->find('A'));
    }
}
