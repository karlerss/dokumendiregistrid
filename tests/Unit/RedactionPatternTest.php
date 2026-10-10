<?php

namespace Tests\Unit;

use App\Lib\Pii\RedactionPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cases taken from redactions that failed in production because PDF text
 * extraction glued fields together, plus the boundaries that must hold.
 */
class RedactionPatternTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'e-mail glued to the next word' => ['villu.vapper@gmail.com', 'identifier', 'kooskõlastamine villu.vapper@gmail.comei saada', 'kooskõlastamine [X]ei saada'],
            'e-mail whose start the extraction missed' => ['keskesconsulting@gmail.com', 'identifier', '(e-post: ekeskesconsulting@gmail.com).', '(e-post: [X]).'],
            'IBAN glued to a word' => ['EE932200221015958175', 'identifier', 'sooritati EE932200221015958175Makse', 'sooritati [X]Makse'],
            'personal code glued to a word' => ['48208160244', 'personal_code', 'registrikood 48208160244Tagastamise', 'registrikood [X]Tagastamise'],
            'phone glued to a letter' => ['56469826', 'identifier', 'Telefon 56469826E- post', 'Telefon [X]E- post'],
            'phrase ending in punctuation, glued' => ['Jaama tänav 41b kinnistu (86801:002:0133)', 'identifier', 'kinnistu (86801:002:0133)asub', 'kinnistu (86801:002:0133)asub'],
            'whole phrase ending in punctuation' => ['Jaama tänav 41b kinnistu (86801:002:0133)', 'identifier', 'Jaama tänav 41b kinnistu (86801:002:0133)asub Harju', '[X]asub Harju'],
            'name glued to a digit' => ['Paul', 'name', 'vennale Paul2,1, Pauliina', 'vennale [X]2,1, Pauliina'],
            'short name stays strict' => ['Tamm', 'name', 'Tamm ja Tammsaare, Tamm2', '[X] ja Tammsaare, [X]2'],
            'name case endings' => ['Mari Maasikas', 'name', 'Mari Maasikase kiri, Mari Maasikasele', '[X] kiri, [X]'],
            'code does not eat a longer number' => ['38606250233', 'personal_code', '38606250233teenistus, 386062502331', '[X]teenistus, 386062502331'],
            'e-mail takes in its whole local part' => ['mari@example.com', 'identifier', 'anna.mari@example.com', '[X]'],
        ];
    }

    #[DataProvider('cases')]
    public function test_replacement(string $surface, string $kind, string $text, string $expected): void
    {
        $this->assertSame($expected, RedactionPlanner::replaceAll($text, [['surface' => $surface, 'replacement' => '[X]', 'kind' => $kind]]));
    }
}
