@extends('layout')

@section('title', 'Eemaldamistaotlused')

@section('content')
    <div class="px-4">
        <div class="container mx-auto">
            <h1 class="text-3xl mb-4 font-bold">Eemaldamistaotlused</h1>

            @if(session('success'))
                <div class="mb-4">
                    <x-bladewind.alert type="success">{{ session('success') }}</x-bladewind.alert>
                </div>
            @endif

            <x-bladewind.table divider="thin">
                <x-slot name="header">
                    <th>Dokument</th>
                    <th>Esitaja</th>
                    <th>Õiguslik alus</th>
                    <th>Staatus</th>
                    <th>Esitatud</th>
                    <th></th>
                </x-slot>
                @forelse($takedownRequests as $takedownRequest)
                    <tr>
                        <td style="max-width: 25vw">
                            @if($takedownRequest->document)
                                <a class="underline text-gray-900"
                                   href="{{ route('document', ['document' => $takedownRequest->document->id, 'slug' => \Illuminate\Support\Str::slug($takedownRequest->document->title)]) }}">
                                    {{ $takedownRequest->document->title }}
                                </a>
                            @elseif($takedownRequest->original_document_url)
                                <a class="underline text-gray-900" target="_blank"
                                   href="{{ $takedownRequest->original_document_url }}">
                                    {{ $takedownRequest->original_document_url }}
                                </a>
                                <span class="text-xs text-gray-400 block">(dokument eemaldatud)</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td>
                            {{ $takedownRequest->author_name }}
                            <span class="text-xs text-gray-400 block">{{ $takedownRequest->author_email }}</span>
                        </td>
                        <td style="white-space: nowrap;">{{ $takedownRequest->legal_basis }}</td>
                        <td>
                            <x-bladewind.tag :label="$takedownRequest->statusLabel()"
                                             :color="$takedownRequest->statusColor()"/>
                        </td>
                        <td style="white-space: nowrap;">{{ $takedownRequest->created_at->format('d.m.Y H:i') }}</td>
                        <td>
                            <div class="flex gap-2 items-center">
                                <x-bladewind.button size="tiny" type="primary" tag="a"
                                                    href="{{ route('takedowns.show', $takedownRequest) }}">
                                    Vaata
                                </x-bladewind.button>
                                @unless($takedownRequest->isResolved())
                                    <form action="{{ route('takedowns.accept', $takedownRequest) }}" method="post">
                                        @csrf
                                        <input type="hidden" name="remove_document" value="1"/>
                                        <x-bladewind.button
                                            size="tiny"
                                            can_submit="true"
                                            color="red"
                                            onclick="return confirm('Kustutada dokument ja rahuldada taotlus? Esitajale saadetakse teavitus.');">
                                            Kustuta &amp; rahulda
                                        </x-bladewind.button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-gray-400 py-6">Eemaldamistaotlusi ei ole.</td>
                    </tr>
                @endforelse
            </x-bladewind.table>

            <div class="mt-4">
                {!! $takedownRequests->onEachSide(5)->links() !!}
            </div>
        </div>
    </div>
@stop
