@props([
    'document',
    'compact' => false,
])

@php
    $pii = $document->latestPiiAssessment;
    $piiExt = $document->latestPiiExtraction;
    $piiSubs = $pii?->extraction?->subjects ?? collect();
    $private = $piiSubs->filter(fn($s) => !$s->isKeep());
@endphp

<div {{ $attributes->merge(['class' => 'text-xs']) }}>
    @if($pii)
        <a href="{{ route('pii.show', $document) }}" class="inline-block">
            <x-bladewind.tag :label="$pii->bandLabel()" :color="$pii->bandColor()"/>
        </a>
        <div class="mt-1">
            {{ $private->count() }} eraisikut / {{ $piiSubs->count() }} isikut
            @if($pii->isReviewed()) · <span class="text-gray-500">{{ $pii->review_action }}</span> @endif
            @if($document->redacted_at) · <span class="text-green-700">redigeeritud {{ $document->redacted_at->format('d.m.Y') }}</span> @endif
        </div>
        @unless($compact)
            @foreach($private->take(3) as $s)
                <div class="text-gray-600">{{ $s->displayName() }} <span class="text-gray-400">· {{ $s->effectiveContextLabel() }}</span></div>
            @endforeach
            @if($pii->extraction?->flags)
                <div class="text-gray-500">{{ collect($pii->extraction->flags)->map(fn($f) => \App\Lib\Pii\Flags::label($f))->take(3)->implode(', ') }}</div>
            @endif
        @endunless
        <a class="underline text-blue-700" href="{{ route('pii.show', $document) }}">vaata</a>
    @elseif($piiExt)
        <span class="text-gray-500">{{ $piiExt->statusLabel() }}</span>
        @if($piiExt->status === 'failed')
            <form method="post" action="{{ route('pii.retry', $piiExt) }}" class="inline">@csrf <button class="underline text-blue-700">uuesti</button></form>
        @endif
        · <a class="underline text-blue-700" href="{{ route('pii.show', $document) }}">vaata</a>
    @else
        <form method="post" action="{{ route('pii.extract', $document) }}">@csrf
            <x-bladewind.button size="tiny" color="blue" can_submit="true">Kontrolli</x-bladewind.button>
        </form>
    @endif
</div>
