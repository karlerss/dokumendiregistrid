<x-mail::message>
@if($recovered)
# Dokumendikontroll töötab jälle

Daemon annab taas elumärke ja ühtegi hosti ei ole pikalt peatatud.
@else
# Dokumendikontroll vajab tähelepanu

@foreach($problems as $problem)
- {{ $problem }}
@endforeach
@endif

<x-mail::button :url="$url">
Vaata kontrolli seisu
</x-mail::button>
</x-mail::message>
