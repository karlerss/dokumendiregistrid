@extends('layout')

@section('title', 'Eemaldamistaotlus #' . $takedownRequest->id)

@section('content')
    <div class="px-4">
        <div class="container mx-auto bg-white mb-8">
            <div class="flex items-center mb-4">
                <h1 class="text-3xl font-bold">Eemaldamistaotlus #{{ $takedownRequest->id }}</h1>
                <span class="ml-4">
                    <x-bladewind.tag :label="$takedownRequest->statusLabel()" :color="$takedownRequest->statusColor()"/>
                </span>
                <a href="{{ route('takedowns.index') }}" class="ml-auto text-blue-500 hover:underline">Tagasi nimekirja</a>
            </div>

            @error('resolution_note')
                <div class="mb-4">
                    <x-bladewind.alert type="error">{{ $message }}</x-bladewind.alert>
                </div>
            @enderror

            <x-bladewind.table compact="true" divider="thin" hover_effect="false">
                <tr>
                    <td class="text-right">Dokument</td>
                    <td class="!text-gray-900">
                        @if($takedownRequest->document)
                            <a class="text-blue-500 hover:underline"
                               href="{{ route('document', ['document' => $takedownRequest->document->id, 'slug' => \Illuminate\Support\Str::slug($takedownRequest->document->title)]) }}">
                                {{ $takedownRequest->document->title }}
                            </a>
                        @else
                            <span class="text-gray-400">Dokument on eemaldatud</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="text-right">Originaal URL</td>
                    <td class="!text-gray-900">
                        <a target="_blank" class="text-blue-500 hover:underline"
                           href="{{ $takedownRequest->original_document_url }}">{{ $takedownRequest->original_document_url }}</a>
                    </td>
                </tr>
                <tr>
                    <td class="text-right">Esitaja</td>
                    <td class="!text-gray-900">{{ $takedownRequest->author_name }}</td>
                </tr>
                <tr>
                    <td class="text-right">E-post</td>
                    <td class="!text-gray-900">{{ $takedownRequest->author_email }}</td>
                </tr>
                <tr>
                    <td class="text-right">Õiguslik alus</td>
                    <td class="!text-gray-900">{{ $takedownRequest->legal_basis }}</td>
                </tr>
                <tr>
                    <td class="text-right">Selgitus</td>
                    <td class="!text-gray-900">{{ $takedownRequest->objection_note ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="text-right">IP</td>
                    <td class="!text-gray-900">{{ $takedownRequest->ip ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="text-right">Esitatud</td>
                    <td class="!text-gray-900">{{ $takedownRequest->created_at->format('d.m.Y H:i') }}</td>
                </tr>
                @if($takedownRequest->isResolved())
                    <tr>
                        <td class="text-right">Lahendatud</td>
                        <td class="!text-gray-900">{{ $takedownRequest->resolved_at?->format('d.m.Y H:i') }}</td>
                    </tr>
                    <tr>
                        <td class="text-right">Lahenduse märkus</td>
                        <td class="!text-gray-900">{{ $takedownRequest->resolution_note ?: '—' }}</td>
                    </tr>
                @endif
            </x-bladewind.table>

            @unless($takedownRequest->isResolved())
                <div class="mt-8 max-w-2xl">
                    <h2 class="text-xl font-bold mb-4">Lahenda taotlus</h2>

                    @if($takedownRequest->document)
                        <form action="{{ route('takedowns.accept', $takedownRequest) }}" method="post" class="mb-6">
                            @csrf
                            <input type="hidden" name="remove_document" value="1"/>
                            <p class="text-sm text-gray-500 mb-2">
                                Rahuldab taotluse, eemaldab dokumendi ja saadab esitajale teavituse (märkuseta).
                            </p>
                            <x-bladewind.button
                                can_submit="true"
                                color="red"
                                icon="trash"
                                onclick="return confirm('Kustutada dokument ja rahuldada taotlus?');">
                                Rahulda ja eemalda dokument
                            </x-bladewind.button>
                        </form>
                    @endif

                    <form action="{{ route('takedowns.accept', $takedownRequest) }}" method="post" id="resolve-form">
                        @csrf
                        <x-bladewind.textarea
                            name="resolution_note"
                            label="Märkus / põhjendus"
                            selected_value="{{ old('resolution_note') }}"
                            rows="4"></x-bladewind.textarea>
                        <p class="text-sm text-gray-500 mb-2">
                            Tagasilükkamisel ja märkusega rahuldamisel on märkus kohustuslik. Esitajale saadetakse teavitus.
                        </p>
                        <div class="flex gap-2">
                            <x-bladewind.button
                                color="green"
                                can_submit="true">
                                Rahulda märkusega
                            </x-bladewind.button>
                            <x-bladewind.button
                                color="red"
                                outline="true"
                                onclick="document.getElementById('resolve-form').action='{{ route('takedowns.deny', $takedownRequest) }}';document.getElementById('resolve-form').submit();">
                                Lükka tagasi
                            </x-bladewind.button>
                        </div>
                    </form>
                </div>
            @endunless
        </div>
    </div>
@stop
