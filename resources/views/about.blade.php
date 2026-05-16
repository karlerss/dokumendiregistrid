@extends('layout')

@section('title', 'Projektist - dokumendiregistrid.karlerss.com')

@section('description', 'dokumendiregistrid.karlerss.com teeb avalike dokumendiregistrite failide sisud otsitavaks, otsimootoritele nähtavaks ning failid otse brauseris vaadatavaks')

@section('content')
    <div class="container mx-auto p-4">
        <div class="grid grid-cols-4">
            <div class="md:col-span-2 col-span-4 with-lists">
                <h1 class="text-2xl font-bold mb-4">Projektist</h1>
                <p class="mb-4 border border-gray-100 shadow p-4 rounded-md">
                    <strong>Lühikokkuvõte:</strong> dokumendiregistrid.karlerss.com
                    teeb avalike dokumendiregistrite failide sisud otsitavaks, otsimootoritele
                    nähtavaks ning failid otse brauseris vaadatavaks.
                </p>
                <p class="mb-4">
                    Esiteks, mul on väga hea meel, et Eesti riigiasutused teevad oma
                    ülesannete täitmisel nii palju dokumente avalikuks. Läbipaistev valitsemine
                    lähendab kodanikku riigile ja teeb erinevate asutuste koostöö efektiivsemaks.
                </p>
                <p class="mb-4">
                    Samas on riigiasutuste dokumendiregistrite sisu olnud seni raskesti avastatav.
                    Minu tähelepanekud on järgnevad:
                </p>
                <h2 class="text-xl font-bold mb-4">Leitavus otsimootorites</h2>
                <p class="mb-4">
                    Lihtne google-otsing <em>site:adr.rik.ee</em> annab tänase seisuga täpselt
                    8 tulemust. Kuna otsimootorid on üks peamisi internetis sisu avastamise viise,
                    leian, et ka riigiasutuste dokumendiregistrite sisu võiks olla avastatav.
                </p>
                <p class="mb-4">
                    Ühtlasi võimaldavad dokumendiregistrid otsida vaid dokumentide pealkirjadest, aga
                    need pealkirjad on arusaadavatel põhjustel sageli üpris üldsõnalised - Vastus, Käskkiri
                    jne.
                </p>
                <p class="mb-4">
                    Samuti kuvavad dokumendiregistrite otsimootorid vaid 100 tulemust korraga
                    ja sedagi vaid siis, kui täpsustada mitut otsingukriteeriumit. Selleks, et asjaosalised
                    saaksid sisuga kursis olla, peab olema võimalik avaldatud dokumentidest kiire ülevaade saada.
                </p>
                <p class="mb-4">
                    Siinne keskkond peaks need kitsaskohad lahendama.
                </p>
                <h2 class="text-xl font-bold mb-4">Kasutusmugavus</h2>
                <p class="mb-4">
                    Ametlikes dokumendiregistrites peab digiallkirjastatud failide
                    sisuga tutvumiseks need arvutisse alla laadima. See on digiriigi kohta kohatult töömahukas ja aeganõudne. Siinne
                    register teeb e-kirjad, digiallkirjastatud failid, pdf-id ja wordi failid
                    nähtavaks otse brauseris.
                </p>
                <h2 class="text-xl font-bold mb-4">Kellele?</h2>
                <p class="mb-4">
                    See veebileht on mõeldud ajakirjanikele, kodanikele, ettevõtetele ja
                    riigiametnikele. Kõigi nende inimeste aeg on väärtuslik ja see keskkond
                    peaks neil hulga aega kokku hoidma.
                </p>
                <h2 class="text-xl font-bold mb-4">Kontakt ja autor</h2>
                <p class="mb-4">
                    <a href="https://www.linkedin.com/in/karl-sander-erss/"
                       class="underline cursor-pointer"
                       target="_blank">
                        Karl-Sander Erss
                    </a>
                </p>
                <h2 class="text-xl font-bold mb-4">Isikuandmete töötlemise põhimõtted</h2>
                <ol>
                    <li class="mb-2">Isikuandmete vastutav töötleja on Karl-Sander Erss (39412110212), email: karl.erss@gmail.com. Töötleja opereerib portaali dokumendiregistrid.karlerss.com, mis on vabalt internetis kättesaadav. </li>
                    <li class="mb-2">Portaal kopeerib riiklikest dokumendiregistritest dokumente, salvestab nende sisud andmebaasi ning teeb ületekstotsinguga otsitavaks.</li>
                    <li class="mb-2">Riiklikest dokumendiregistritest kopeeritud dokumendid võivad sisaldada isikuandmeid.</li>
                    <li class="mb-2">Kui isikuandmed on riiklikus dokumendiregistris avaldatud, eeldab töötleja, et need on avalik teave (AvTS § 3), need on avalikustatud õiguspäraselt ning antud tingimusteta taaskasutamisse (AvTS § 3¹ lg 9).</li>
                    <li class="mb-2">Isikuandmete töötlemise alus on õigustatud huvi (IKÜM Art. 6 lg 1 p f). Avalik huvi avalike andmete vastu on eelduslik (PS § 44, AvTS § 1).</li>
                    <li class="mb-2">Töötleja hinnangul on võimalik isikuandmete kaitse riive proportsionaalne, kuna see põhineb dokumendiregistrit pidava riigiasutuse otsusel need andmed avalikustada. Töötleja töötleb üksnes isikuandmeid, mis on riigi- või kohaliku omavalitsuse asutuse või avalik-õigusliku juriidilise isiku poolt juba avalikustatud.</li>
                    <li class="mb-2">Isikuandmeid sisaldavaid dokumente hoitakse kuni 75 aastat.</li>
                    <li class="mb-2">Isikuandmete kustutamispäringute lahendamisel võetakse arvesse: kas dokument on algsest dokumendiregistrist kustutatud, dokumendi loomise kuupäeva ja avaliku huvi suurust dokumendi säilitamise vastu.</li>
                    <li class="mb-2">Portaali serveriteenuse pakkuja on Hetzner Online GmbH (Industriestr. 25 91710 Gunzenhausen, Saksamaa). Server asub Hetzneri Helsingi andmekeskuses.</li>
                    <li class="mb-2">Failide majutusteenust pakub Cloudflare, Inc. (101 Townsend Street, San Francisco, CA 94107, USA). Cloudflare <a href="https://blog.cloudflare.com/r2-ga/#:~:text=the%20object%20store.-,Jurisdictional%20Restrictions,-While%20we%20don%E2%80%99t">ei edasta andmeid</a> väljapoole Euroopa Liitu.</li>
                    <li class="mb-2">Töötleja on kontrollinud nende alamtöötlejate isikuandmete kaitse põhimõtteid ja leidnud, et need on kooskõlas IKÜM-ga.</li>
                </ol>
                <p class="mb-4"><em>Uuendatud 05.09.2024</em></p>
            </div>
        </div>

    </div>
@endsection
