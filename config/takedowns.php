<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public-sector requesters
    |--------------------------------------------------------------------------
    |
    | Email domains of state and local-government bodies that file takedown
    | requests. The admin list can hide requests from these senders so the
    | ones from private persons and companies stand out. A domain matches the
    | address's domain exactly or any of its subdomains.
    |
    | Seen in actual requests: konkurentsiamet, terviseamet, transpordiamet,
    | politsei, rik, tehik, taltech, rtk, rmk, ria, paasteamet, kohus, ekei.
    | The rest are the bodies whose registries this site indexes.
    |
    */

    'public_sector_domains' => [
        // Ministries and government office
        'agri.ee',
        'fin.ee',
        'hm.ee',
        'just.ee',
        'kaitseministeerium.ee',
        'kliimaministeerium.ee',
        'kul.ee',
        'mkm.ee',
        'riigikantselei.ee',
        'siseministeerium.ee',
        'sm.ee',
        'vm.ee',

        // Parliament, courts, prosecution
        'riigikogu.ee',
        'riigikohus.ee',
        'kohus.ee',
        'prokuratuur.ee',
        'ekei.ee',

        // Agencies, boards, inspectorates
        'aki.ee',
        'egt.ee',
        'emta.ee',
        'kaitseliit.ee',
        'keskkonnaamet.ee',
        'konkurentsiamet.ee',
        'maaamet.ee',
        'mil.ee',
        'paasteamet.ee',
        'politsei.ee',
        'ra.ee',
        'ria.ee',
        'rik.ee',
        'rkik.ee',
        'rmk.ee',
        'rtk.ee',
        'smit.ee',
        'sotsiaalkindlustusamet.ee',
        'tehik.ee',
        'terviseamet.ee',
        'transpordiamet.ee',
        'ttja.ee',
        'tootukassa.ee',

        // Local government
        'tallinnlv.ee',
        'tallinn.ee',
        'tartu.ee',

        // Public universities and research
        'taltech.ee',
        'ut.ee',
    ],

];
