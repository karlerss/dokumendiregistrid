<x-mail::message>
# Kinnitage oma e-posti aadress

Tere, {{ $takedownRequest->author_name }}!

Olete esitanud taotluse järgmise dokumendi eemaldamiseks:

{{ $takedownRequest->original_document_url }}

Taotluse menetlemiseks kinnitage palun oma e-posti aadress. Teie kinnituskood on:

# {{ $code }}

Sisestage see kood taotluse jälgimise lehel. Kood kehtib {{ \App\Models\TakedownRequest::VERIFICATION_CODE_TTL_MINUTES }} minutit.

<x-mail::button :url="$url">
Ava taotluse leht
</x-mail::button>

Kui Te ei esitanud seda taotlust, võite selle kirja eirata.

Lugupidamisega,<br>
{{ config('app.name') }}
</x-mail::message>
