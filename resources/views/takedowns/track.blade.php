@extends('layout')

@section('title', 'Eemaldamistaotluse staatus')

@section('content')
    <div class="px-4">
        <div class="container mx-auto bg-white mb-8 max-w-2xl">
            <div class="flex items-center mb-4">
                <h1 class="text-3xl font-bold">Eemaldamistaotlus</h1>
                <span class="ml-4">
                    <x-bladewind.tag :label="$takedownRequest->statusLabel()" :color="$takedownRequest->statusColor()"/>
                </span>
            </div>

            @if(session('success'))
                <div class="mb-4">
                    <x-bladewind.alert type="success">{{ session('success') }}</x-bladewind.alert>
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4">
                    <x-bladewind.alert type="error">{{ session('error') }}</x-bladewind.alert>
                </div>
            @endif

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
                    <td class="text-right">Esitaja</td>
                    <td class="!text-gray-900">{{ $takedownRequest->author_name }}</td>
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

            @if($takedownRequest->status === \App\Models\TakedownRequest::STATUS_UNVERIFIED)
                <div class="mt-8">
                    <h2 class="text-xl font-bold mb-2">Kinnitage oma e-posti aadress</h2>
                    <p class="text-sm text-gray-500 mb-4">
                        Saatsime aadressile <strong>{{ $takedownRequest->author_email }}</strong> 6-kohalise kinnituskoodi.
                        Sisestage see allpool. Taotlust hakatakse menetlema alles pärast e-posti aadressi kinnitamist.
                    </p>

                    @error('verification_code')
                        <div class="mb-4">
                            <x-bladewind.alert type="error">{{ $message }}</x-bladewind.alert>
                        </div>
                    @enderror

                    <form action="{{ route('takedowns.verify', $takedownRequest) }}" method="post">
                        @csrf
                        <x-bladewind.input
                            name="verification_code"
                            label="Kinnituskood"
                            required="true"
                            placeholder="000000"/>
                        <x-bladewind.button can_submit="true">
                            Kinnita
                        </x-bladewind.button>
                    </form>

                    <form action="{{ route('takedowns.resend', $takedownRequest) }}" method="post" class="mt-4">
                        @csrf
                        <p class="text-sm text-gray-500">
                            Ei saanud koodi?
                            <button type="submit" class="text-blue-500 hover:underline">Saada kood uuesti</button>
                        </p>
                    </form>
                </div>
            @elseif($takedownRequest->status === \App\Models\TakedownRequest::STATUS_PENDING)
                <p class="mt-6 text-gray-600">
                    Teie e-posti aadress on kinnitatud ja taotlus on ootel. Vaatame selle üle ja teavitame Teid tulemusest e-posti teel.
                </p>
            @elseif($takedownRequest->status === \App\Models\TakedownRequest::STATUS_ACCEPTED)
                <p class="mt-6 text-gray-600">Teie taotlus on rahuldatud.</p>
            @elseif($takedownRequest->status === \App\Models\TakedownRequest::STATUS_DENIED)
                <p class="mt-6 text-gray-600">Teie taotlus lükati tagasi.</p>
            @endif
        </div>
    </div>
@stop
