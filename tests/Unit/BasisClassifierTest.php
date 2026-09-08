<?php

namespace Tests\Unit;

use App\Lib\Restriction\BasisClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BasisClassifierTest extends TestCase
{
    /**
     * Real basis strings observed in the production database.
     */
    public static function personalDataBases(): array
    {
        return [
            ['AvTS § 35 lg 1 p 12'],
            ['AvTS § 35 lg 1 p 12 '],
            ['§ 35 lg 1 p 12 teave'],
            ['p 12'],
            ['12'],
            ['AvTS § 35 lg 1 p 12,19'],
            ['AvTS § 35 lg 1 p 12, AvTS § 35 lg 1 p 13'],
            ['AvTS § 35 lg 1 p 1, AvTS § 35 lg 1 p 12'],
            ['AvTS § 35 lg 1 p 1, 12'],
            ['AvTS § 35 lg 1 p 11-15'],
            ['AvTS § 35 lg 1 p 12, LS § 184 lg 3 p 1-4'],
            ['AvTS § 35 lg 1 p 12; PPVS § 4 lg 5'],
            ['AvTS § 35 lg 1 p 12 - Eraelu puutumatust kahjustav teave'],
            ['AvTS § 35 lg 1 p 12 Eraelu puutumatust kahjustav teave; piirangu kehtivus: 22.12.2025-22.12.2100'],
            ['AvTS § 35 lg 1 p 12 teave, mis sisaldab isikuandmeid, kui sellisele teabele juurdepääsu võimaldamine kahjustaks oluliselt andmesubjekti eraelu puutumatust'],
            ['mis sisaldab isikuandmeid'],
            ['mis sisaldab eriliiki isikuandmeid'],
            ['mis kahjustab eraelu puutumatust'],
            ['Eraelu puutumatust kahjustav teave; piirangu kehtivus: 22.12.2025-22.12.2100'],
            ['AvTS § 35 lg 1 punkt 12'],
            ['AvTS § 35 lg 1 p. 12'],
            ['AVTS § 35 LG 1 P 12'],
        ];
    }

    public static function otherBases(): array
    {
        return [
            [null],
            [''],
            ['   '],
            ['AvTS § 35 lg 1 p 1'],
            ['AvTS § 35 lg 1 p 1 Kriminaal- või väärteomenetluses kogutud teave'],
            ['AvTS § 35 lg 1 p 2 riikliku järelevalve menetluse käigus kogutud teave kuni selle kohta tehtud otsuse jõustumiseni'],
            ['AvTS § 35 lg 1 p 17 teave, mille avalikustamine võib kahjustada ärisaladust'],
            ['AvTS § 35 lg 1 p 1, 5 (1)'],
            ['AvTS § 35 lg 1 p 5 (1)'],
            ['AvTS § 35 lg 1 p 3¹'],
            ['AvTS § 35 lg 1 p 3 (1) Struktuuriüksuse koosseisu, ametnikke ja töötajaid ning nende ülesandeid käsitlev teave'],
            ['AvTS § 35 lg 1 p 9 , AvTS § 35 lg 1 p 10'],
            ['AvTS § 35 lg 1 p 10, AvTS § 35 lg 1 p 17'],
            ['AvTS § 35 lg 1 p 6 (1), AvTS § 35 lg 1 p 10, AvTS § 35 lg 1 p 9'],
            ['AvTS § 35 lg 1 p 18 (1)'],
            ['AvTS § 35 lg 2 p 1'],
            ['AvTS § 35 lg 2 p 2 dokumendi kavand ja selle juurde kuuluvad dokumendid enne nende vastuvõtmist või allakirjutamist'],
            ['AvTS § 35 lg 2 p 3'],
            ['AvTS-i § 40 lg 1 alusel tähtaega pikendatakse kuni viie aasta võrra, sest juurdepääsupiirangu kehtestamise põhjus püsib'],
            ['HkMS § 89 lg 1'],
            ['LS § 184 lg 3 p 1-4'],
            ['RHS § 110 lg 5'],
            ['ÕKS § 23 lg 8'],
            ['ÜSS2021_2027 § 20 lg 3'],
            ['19; PPVS § 4 lg 5'],
            ['19; KrMS §433 lg 4'],
            ['19'],
            ['3'],
            ['Struktuurüksus on tunnistatud'],
        ];
    }

    #[DataProvider('personalDataBases')]
    public function test_recognises_personal_data_bases(string $basis): void
    {
        $this->assertTrue(BasisClassifier::isPersonalData($basis), "Expected personal data: '$basis'");
    }

    #[DataProvider('otherBases')]
    public function test_rejects_other_bases(?string $basis): void
    {
        $this->assertFalse(BasisClassifier::isPersonalData($basis), "Expected NOT personal data: '$basis'");
    }

    public function test_any_personal_data_over_a_split_list(): void
    {
        // AdrFetcher splits "AvTS § 35 lg 1 p 1, 12" on ", " before storing.
        $this->assertTrue(BasisClassifier::anyPersonalData(['AvTS § 35 lg 1 p 1', '12']));
        $this->assertFalse(BasisClassifier::anyPersonalData(['AvTS § 35 lg 1 p 1', '5 (1)']));
        $this->assertFalse(BasisClassifier::anyPersonalData([]));
    }
}
