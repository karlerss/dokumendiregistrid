<x-mail::message>
# Eemaldamistaotlus rahuldati

Tere, {{ $takedownRequest->author_name }}!

Olete esitanud taotluse järgmise dokumendi eemaldamiseks:

{{ $takedownRequest->original_document_url }}

Teatame, et **taotlus rahuldati**.

**Märkus:**

{{ $takedownRequest->resolution_note }}

Kui Teil on küsimusi, võtke meiega ühendust vastates sellele kirjale.

Lugupidamisega,<br>
{{ config('app.name') }}
</x-mail::message>
