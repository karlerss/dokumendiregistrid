@extends('layout')

@section('title', 'Dokumendikontroll')

@section('content')
    <div class="px-4">
        <div class="w-full">
            <h1 class="text-3xl mb-4 font-bold">Dokumendikontroll</h1>

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

            {{-- Health --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 text-sm">
                <div class="border rounded p-4 {{ $heartbeatStale ? 'border-red-400 bg-red-50' : 'border-gray-200' }}">
                    <div class="text-gray-500">Daemon</div>
                    @if($heartbeat)
                        <div class="text-lg font-semibold">{{ $heartbeatStale ? 'Seiskunud' : 'Töötab' }}</div>
                        <div class="text-gray-500">viimane elumärk {{ $heartbeat->diffForHumans() }}</div>
                    @else
                        <div class="text-lg font-semibold">Pole käivitatud</div>
                    @endif
                </div>
                <div class="border border-gray-200 rounded p-4">
                    <div class="text-gray-500">Viimased 24 h</div>
                    <div class="text-lg font-semibold">{{ number_format($stats['checks']) }} kontrolli</div>
                    <div class="text-gray-500">{{ $stats['changes'] }} muutust · {{ $stats['errors'] }} viga</div>
                </div>
                <div class="border border-gray-200 rounded p-4">
                    <div class="text-gray-500">Järjekord</div>
                    <div class="text-lg font-semibold">{{ number_format($queue->due ?? 0) }} ootel</div>
                    <div class="text-gray-500">
                        {{ number_format($queue->total ?? 0) }} kokku ·
                        {{ number_format($queue->unchecked ?? 0) }} kontrollimata ·
                        {{ number_format($queue->erroring ?? 0) }} veaga
                    </div>
                </div>
                <div class="border border-gray-200 rounded p-4">
                    <div class="text-gray-500">Allika seis</div>
                    <div class="text-lg font-semibold">{{ number_format($queue->public ?? 0) }} avalik</div>
                    <div class="text-gray-500">
                        {{ number_format($queue->restricted ?? 0) }} piiratud ·
                        {{ number_format($queue->gone ?? 0) }} kadunud ·
                        {{ number_format($hiddenCount) }} peidetud
                    </div>
                </div>
            </div>

            @if(count($pausedHosts))
                <div class="mb-6">
                    <x-bladewind.alert type="warning" shade="faint">
                        Peatatud hostid:
                        @foreach($pausedHosts as $host => $until)
                            <span class="font-mono">{{ $host }}</span> (kuni {{ $until->format('d.m.Y H:i') }}){{ $loop->last ? '' : ', ' }}
                        @endforeach
                    </x-bladewind.alert>
                </div>
            @endif

            {{-- Filters --}}
            <div class="flex flex-wrap gap-2 items-center mb-4 text-sm">
                @php
                    $filters = [
                        null => ['Kõik', $counts->total ?? 0],
                        'personal' => ['Isikuandmed', $counts->personal ?? 0],
                        'restricted' => ['Piiratud', $counts->restricted ?? 0],
                        'gone' => ['Kadunud', $counts->gone ?? 0],
                        'public' => ['Taas avalik', $counts->public ?? 0],
                    ];
                @endphp
                @foreach($filters as $key => [$label, $count])
                    <a href="{{ route('recheck.index', array_filter(['type' => $key, 'all' => $showAll ? 1 : null])) }}"
                       class="px-3 py-1 rounded border {{ $type === $key ? 'bg-gray-900 text-white border-gray-900' : 'border-gray-300 hover:bg-gray-100' }}">
                        {{ $label }} <span class="opacity-70">{{ $count }}</span>
                    </a>
                @endforeach
                <a href="{{ route('recheck.index', array_filter(['type' => $type, 'all' => $showAll ? null : 1])) }}"
                   class="ml-2 underline text-gray-600">{{ $showAll ? 'Ainult läbivaatamata' : 'Näita ka läbivaadatuid' }}</a>

                @if(!$showAll && ($counts->total ?? 0) > 0)
                    <form method="post" action="{{ route('recheck.acknowledgeAll') }}" class="ml-auto"
                          onsubmit="return confirm('Märgi kõik filtreeritud muutused läbivaadatuks?')">
                        @csrf
                        <input type="hidden" name="type" value="{{ $type }}">
                        <x-bladewind.button size="tiny" color="gray" can_submit="true">Märgi kõik läbivaadatuks</x-bladewind.button>
                    </form>
                @endif
            </div>

            <x-bladewind.table divider="thin">
                <x-slot name="header">
                    <th>Aeg</th>
                    <th>Dokument</th>
                    <th>Muutus</th>
                    <th>Alus</th>
                    <th>Nähtav</th>
                    <th></th>
                </x-slot>
                @forelse($changes as $change)
                    @php $doc = $change->document; @endphp
                    <tr class="{{ $change->personal_data ? 'bg-red-50' : '' }}">
                        <td class="whitespace-nowrap text-gray-500">{{ $change->occurred_at->format('d.m.Y H:i') }}</td>
                        <td style="max-width: 30vw">
                            @if($doc)
                                <a class="underline text-gray-900"
                                   href="{{ route('document', ['document' => $doc->id, 'slug' => \Illuminate\Support\Str::slug($doc->ai_title ?? $doc->title)]) }}">
                                    {{ \Illuminate\Support\Str::limit($doc->title, 90) }}
                                </a>
                                <div class="text-xs text-gray-500">
                                    {{ $doc->organisation->slug }} · {{ $doc->reference }} · {{ $doc->registration_date->format('d.m.Y') }}
                                    · <a class="underline" target="_blank" rel="noopener" href="{{ $doc->url }}">allikas</a>
                                    @if($doc->takedownRequests()->exists())
                                        · <span class="text-orange-700">eemaldamistaotlus</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-gray-400">(dokument kustutatud)</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            <x-bladewind.tag :label="$change->transitionLabel()" :color="$change->statusColor()"/>
                            @if($change->personal_data)
                                <div class="text-xs text-red-700 font-semibold mt-1">isikuandmed (p 12)</div>
                            @endif
                            @if($change->http_status)
                                <div class="text-xs text-gray-400">HTTP {{ $change->http_status }}</div>
                            @endif
                        </td>
                        <td style="max-width: 20vw" class="text-xs">
                            {{ $change->basis ?: ($change->to_restriction ?: '—') }}
                            @if($doc?->remoteState?->remote_restriction_change_basis)
                                <div class="text-gray-500 mt-1">{{ $doc->remoteState->remote_restriction_change_basis }}</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if($doc)
                                {{ $doc->visible ? 'jah' : 'ei' }}
                                @if($doc->files()->count() === 0)
                                    <div class="text-xs text-gray-400">faile pole</div>
                                @endif
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if($change->isAcknowledged())
                                <span class="text-xs text-gray-500">
                                    läbivaadatud {{ $change->acknowledged_at->format('d.m.Y') }}
                                    @if($change->action) · {{ $change->action }} @endif
                                </span>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @if($doc && $change->to_status !== 'public' && $doc->visible)
                                        <form method="post" action="{{ route('recheck.hide', $change) }}">@csrf
                                            <x-bladewind.button size="tiny" color="yellow" can_submit="true">Peida</x-bladewind.button>
                                        </form>
                                    @endif
                                    @if($doc && $change->to_status !== 'public' && $doc->files()->count() > 0)
                                        <form method="post" action="{{ route('recheck.deleteFiles', $change) }}"
                                              onsubmit="return confirm('Kustuta dokumendi failid jäädavalt ja peida dokument?')">@csrf
                                            <x-bladewind.button size="tiny" color="red" can_submit="true">Kustuta failid</x-bladewind.button>
                                        </form>
                                    @endif
                                    @if($doc && $change->to_status === 'public' && !$doc->visible)
                                        <form method="post" action="{{ route('recheck.unhide', $change) }}">@csrf
                                            <x-bladewind.button size="tiny" color="green" can_submit="true">Näita</x-bladewind.button>
                                        </form>
                                    @endif
                                    @if($doc && $change->to_status === 'public' && $doc->organisation->fetcher_type === 'delta-adr')
                                        <form method="post" action="{{ route('recheck.refetch', $change) }}"
                                              onsubmit="return confirm('Lae failid allikast uuesti?')">@csrf
                                            <x-bladewind.button size="tiny" color="blue" can_submit="true">Lae uuesti</x-bladewind.button>
                                        </form>
                                    @endif
                                    <form method="post" action="{{ route('recheck.acknowledge', $change) }}">@csrf
                                        <x-bladewind.button size="tiny" color="gray" can_submit="true">Läbivaadatud</x-bladewind.button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-gray-400 py-8">Läbivaatamata muutusi pole.</td>
                    </tr>
                @endforelse
            </x-bladewind.table>

            <div class="mt-4">
                {{ $changes->links() }}
            </div>
        </div>
    </div>
@endsection
