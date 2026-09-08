<x-mail::message>
# Dokumendikontrolli kokkuvõte

Viimase ööpäeva jooksul leidis kontroll **{{ $newCount }}** muutust
@if($personalCount > 0)
, neist **{{ $personalCount }}** isikuandmete piirangu (AvTS § 35 lg 1 p 12) tõttu
@endif
. Läbi vaatamata muutusi kokku: **{{ $pendingCount }}**.

@foreach($changes as $change)
- {!! $change->personal_data ? '**[isikuandmed]** ' : '' !!}{{ $change->transitionLabel() }} — {{ $change->document?->organisation?->slug }} · {{ \Illuminate\Support\Str::limit($change->document?->title ?? '(dokument kustutatud)', 80) }}@if($change->basis) · {{ \Illuminate\Support\Str::limit($change->basis, 60) }}@endif

@endforeach
@if($newCount > $changes->count())

… ja veel {{ $newCount - $changes->count() }}.
@endif

<x-mail::button :url="$url">
Vaata kontrolli järjekorda
</x-mail::button>
</x-mail::message>
