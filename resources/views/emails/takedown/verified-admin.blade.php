<x-mail::message>
# Uus kinnitatud eemaldamistaotlus

Kasutaja on kinnitanud uue eemaldamistaotluse.

**Dokument:** {{ $takedownRequest->original_document_url }}

**Esitaja:** {{ $takedownRequest->author_name }} ({{ $takedownRequest->author_email }})

**Õiguslik alus:** {{ $takedownRequest->legal_basis }}

**Selgitus:**

{{ $takedownRequest->objection_note ?: '—' }}

<x-mail::button :url="$url">
Vaata taotlust
</x-mail::button>
</x-mail::message>