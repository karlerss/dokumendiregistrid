<?php

namespace Tests\Unit;

use App\Lib\Pii\PersonalCode;
use PHPUnit\Framework\TestCase;

class PersonalCodeTest extends TestCase
{
    public function test_valid_codes(): void
    {
        $this->assertTrue(PersonalCode::isValid('37605030299'));
        $this->assertTrue(PersonalCode::isValid('49403136515'));
        $this->assertTrue(PersonalCode::isValid('50101010009'));
    }

    public function test_invalid_codes(): void
    {
        $this->assertFalse(PersonalCode::isValid('37605030298'), 'wrong checksum');
        $this->assertFalse(PersonalCode::isValid('97605030299'), 'first digit');
        $this->assertFalse(PersonalCode::isValid('37613030299'), 'month 13');
        $this->assertFalse(PersonalCode::isValid('3760503029'), 'too short');
        $this->assertFalse(PersonalCode::isValid(null));
        $this->assertFalse(PersonalCode::isValid('12345678901'));
    }

    public function test_find_all_ignores_reference_numbers_and_dedupes(): void
    {
        $text = 'Otsus nr 1.1-2/24/35, isik 37605030299 (ik 37605030299), viide 12345678901, konto 49403136515123';
        $this->assertSame(['37605030299'], PersonalCode::findAll($text));
    }

    public function test_normalize(): void
    {
        $this->assertSame('37605030299', PersonalCode::normalize('3760503 0299'));
        $this->assertNull(PersonalCode::normalize(null));
        $this->assertNull(PersonalCode::normalize('abc'));
    }
}
