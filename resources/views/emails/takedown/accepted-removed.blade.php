<x-mail::message>
# Eemaldamistaotlus rahuldati

Tere, {{ $takedownRequest->author_name }}!

Olete esitanud taotluse järgmise dokumendi eemaldamiseks:

{{ $takedownRequest->original_document_url }}

Teatame, et **taotlus rahuldati ja dokument on meie registrist eemaldatud**.

Kui Teil on küsimusi, võtke meiega ühendust vastates sellele kirjale.

Lugupidamisega,<br>
{{ config('app.name') }}
</x-mail::message>
