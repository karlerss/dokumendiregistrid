@extends('layout')

@section('title', 'Isikuandmed')

@section('content')
    <div class="px-4">
        <div class="w-full">
            <h1 class="text-3xl mb-4 font-bold">Isikuandmed</h1>

            @if(session('success'))
                <div class="mb-4"><x-bladewind.alert type="success">{{ session('success') }}</x-bladewind.alert></div>
            @endif
            @if(session('error'))
                <div class="mb-4"><x-bladewind.alert type="error">{{ session('error') }}</x-bladewind.alert></div>
            @endif

            {{-- Health --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 text-sm">
                <div class="border rounded p-4 {{ $schedulerStale ? 'border-red-400 bg-red-50' : 'border-gray-200' }}">
                    <div class="text-gray-500">Töötleja</div>
                    <div class="text-lg font-semibold">{{ $schedulerStale ? 'Seiskunud' : 'Töötab' }}</div>
                    <div class="text-gray-500">
                        @if($lastRunAt) viimane järjekorrastus {{ $lastRunAt->diffForHumans() }} @else pole käivitatud @endif
                        · {{ $queueSize }} tööd järjekorras
                        @if($failedJobs) · <span class="text-red-700">{{ $failedJobs }} ebaõnnestunud tööd</span> @endif
                    </div>
                </div>
                <div class="border border-gray-200 rounded p-4">
                    <div class="text-gray-500">Ekstraktsioon</div>
                    <div class="text-lg font-semibold">{{ number_format($backlog->done ?? 0) }} tehtud</div>
                    <div class="text-gray-500">
                        {{ number_format($selectionRemaining) }} valikus ootel ·
                        {{ number_format($backlog->pending ?? 0) }} järjekorras ·
                        <span class="{{ ($backlog->failed ?? 0) ? 'text-red-700' : '' }}">{{ number_format($backlog->failed ?? 0) }} ebaõnnestunud</span> ·
                        {{ number_format($backlog->needs_ocr ?? 0) }} OCR ·
                        {{ number_format($backlog->too_large ?? 0) }} mahukas
                    </div>
                </div>
                <div class="border rounded p-4 {{ $capHit ? 'border-orange-400 bg-orange-50' : 'border-gray-200' }}">
                    <div class="text-gray-500">Tokenid</div>
                    <div class="text-lg font-semibold">{{ number_format($tokensToday) }} / {{ number_format($cap) }} täna</div>
                    <div class="text-gray-500">
                        {{ number_format($backlog->tokens_total ?? 0) }} kokku · mudel {{ $model }} · prompt v{{ $promptVersion }} · reeglid v{{ $rulesVersion }}
                        @if($capHit) · <span class="text-orange-700">päevalimiit täis, automaatne valik peatatud</span> @endif
                    </div>
                </div>
                <div class="border border-gray-200 rounded p-4">
                    <div class="text-gray-500">Hinnangud</div>
                    <div class="text-lg font-semibold">{{ number_format($bandCounts->unreviewed ?? 0) }} läbivaatamata</div>
                    <div class="text-gray-500">
                        <span class="text-red-700">{{ number_format($bandCounts->high ?? 0) }} kõrge</span> ·
                        <span class="text-yellow-700">{{ number_format($bandCounts->warn ?? 0) }} hoiatus</span> ·
                        {{ number_format($bandCounts->info ?? 0) }} info ·
                        {{ number_format($bandCounts->total ?? 0) }} kokku
                    </div>
                </div>
            </div>

            @if($failures->count() || $inProgress->count() || $failedRedactions->count())
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 text-sm">
                    @if($failures->count())
                        <div class="border border-red-300 rounded p-4">
                            <div class="font-semibold mb-2">Ebaõnnestunud ekstraktsioonid</div>
                            @foreach($failures as $f)
                                <div class="flex items-start gap-2 mb-1">
                                    <a class="underline" href="{{ route('pii.show', $f->document_id) }}">#{{ $f->document_id }}</a>
                                    <span class="text-gray-500 truncate" title="{{ $f->error }}">{{ \Illuminate\Support\Str::limit($f->error, 80) }}</span>
                                    <form method="post" action="{{ route('pii.retry', $f) }}" class="ml-auto">@csrf
                                        <button class="underline text-blue-700">uuesti</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($inProgress->count())
                        <div class="border border-blue-300 rounded p-4">
                            <div class="font-semibold mb-2">Pooleliolevad redigeerimised</div>
                            @foreach($inProgress as $r)
                                <div class="mb-1">
                                    <a class="underline" href="{{ route('pii.show', $r->document_id) }}">#{{ $r->document_id }}</a>
                                    <span class="text-gray-500">tekst: {{ \App\Models\PiiRedaction::statusLabel($r->text_status) }} · failid: {{ \App\Models\PiiRedaction::statusLabel($r->files_status) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($failedRedactions->count())
                        <div class="border border-red-300 rounded p-4">
                            <div class="font-semibold mb-2">Redigeerimise vead</div>
                            @foreach($failedRedactions as $r)
                                <div class="mb-1">
                                    <a class="underline" href="{{ route('pii.show', $r->document_id) }}">#{{ $r->document_id }}</a>
                                    <span class="text-gray-500">tekst: {{ \App\Models\PiiRedaction::statusLabel($r->text_status) }} · failid: {{ \App\Models\PiiRedaction::statusLabel($r->files_status) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- Filters --}}
            <form method="get" class="flex flex-wrap gap-2 items-end mb-4 text-sm">
                @php $f = $filters; @endphp
                <label>Aste
                    <select name="band" class="border rounded px-2 py-1 block">
                        <option value="">kõik</option>
                        @foreach(['HIGH' => 'kõrge', 'WARN' => 'hoiatus', 'INFO' => 'info'] as $k => $l)
                            <option value="{{ $k }}" @selected($f['band'] === $k)>{{ $l }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Läbivaatus
                    <select name="review" class="border rounded px-2 py-1 block">
                        <option value="unreviewed" @selected($f['review'] === 'unreviewed')>läbivaatamata</option>
                        <option value="reviewed" @selected($f['review'] === 'reviewed')>läbivaadatud</option>
                        <option value="all" @selected($f['review'] === 'all')>kõik</option>
                    </select>
                </label>
                <label>Asutus
                    <select name="org" class="border rounded px-2 py-1 block max-w-xs">
                        <option value="">kõik</option>
                        @foreach($organisations as $o)
                            <option value="{{ $o->id }}" @selected((string)$f['orgId'] === (string)$o->id)>{{ $o->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Sari
                    <input name="series" value="{{ $f['series'] }}" class="border rounded px-2 py-1 block" placeholder="täpne sarja nimi">
                </label>
                <label>Kontekst
                    <select name="context" class="border rounded px-2 py-1 block max-w-xs">
                        <option value="">kõik</option>
                        @foreach($contexts as $k => $l)
                            <option value="{{ $k }}" @selected($f['context'] === $k)>{{ $l }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Märgis
                    <select name="flag" class="border rounded px-2 py-1 block max-w-xs">
                        <option value="">kõik</option>
                        @foreach($flagLabels as $k => $l)
                            <option value="{{ $k }}" @selected($f['flag'] === $k)>{{ $l }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Asi
                    <select name="matter" class="border rounded px-2 py-1 block">
                        <option value="">kõik</option>
                        @foreach($matters as $m)
                            <option value="{{ $m }}" @selected($f['matter'] === $m)>{{ $m }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Redigeeritud
                    <select name="redacted" class="border rounded px-2 py-1 block">
                        <option value="">kõik</option>
                        <option value="1" @selected($f['redacted'] === '1')>jah</option>
                        <option value="0" @selected($f['redacted'] === '0')>ei</option>
                    </select>
                </label>
                <label class="flex items-center gap-1 pb-1"><input type="checkbox" name="group" value="1" @checked($group)> grupeeri asutuse ja sarja järgi</label>
                <x-bladewind.button size="tiny" can_submit="true">Filtreeri</x-bladewind.button>
                <a href="{{ route('pii.index') }}" class="underline text-gray-600 pb-1">tühjenda</a>
            </form>

            @if($group)
                <x-bladewind.table divider="thin">
                    <x-slot name="header">
                        <th>Asutus</th>
                        <th>Sari</th>
                        <th>Kokku</th>
                        <th>Kõrge</th>
                        <th>Hoiatus</th>
                        <th>Info</th>
                        <th>Läbivaatamata</th>
                        <th>Redigeeritud</th>
                    </x-slot>
                    @forelse($groups as $g)
                        <tr>
                            <td>{{ $g->org_name }}</td>
                            <td><a class="underline" href="{{ route('pii.index', array_filter(array_merge($filters, ['org' => $g->organisation_id, 'series' => $g->series, 'orgId' => null]))) }}">{{ $g->series ?: '—' }}</a></td>
                            <td>{{ $g->total }}</td>
                            <td class="text-red-700">{{ $g->high }}</td>
                            <td class="text-yellow-700">{{ $g->warn }}</td>
                            <td>{{ $g->info }}</td>
                            <td>{{ $g->unreviewed }}</td>
                            <td>{{ $g->redacted }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-gray-400 py-8">Hinnanguid pole.</td></tr>
                    @endforelse
                </x-bladewind.table>
            @else
                <x-bladewind.table divider="thin">
                    <x-slot name="header">
                        <th>Aste</th>
                        <th>Dokument</th>
                        <th>Isikud</th>
                        <th>Märgised</th>
                        <th>Seis</th>
                        <th></th>
                    </x-slot>
                    @forelse($assessments as $a)
                        @php
                            $doc = $a->document;
                            $subs = $a->extraction?->subjects ?? collect();
                            $private = $subs->filter(fn($s) => !$s->isKeep())->count();
                            $active = $doc?->piiRedactions->first(fn($r) => $r->isActive() || $r->isInProgress());
                        @endphp
                        <tr class="{{ $a->band === 'HIGH' ? 'bg-red-50' : ($a->band === 'WARN' ? 'bg-yellow-50' : '') }}">
                            <td class="whitespace-nowrap">
                                <x-bladewind.tag :label="$a->bandLabel()" :color="$a->bandColor()"/>
                                <div class="text-xs text-gray-500 mt-1">{{ $a->recommendationLabel() }}</div>
                            </td>
                            <td style="max-width: 30vw">
                                @if($doc)
                                    <a class="underline text-gray-900" href="{{ route('pii.show', $doc) }}">{{ \Illuminate\Support\Str::limit($doc->title, 90) }}</a>
                                    <div class="text-xs text-gray-500">
                                        {{ $doc->organisation->slug }} · {{ $doc->reference }} · {{ $doc->registration_date->format('d.m.Y') }}
                                        · {{ \Illuminate\Support\Str::limit($doc->series, 50) }}
                                        · <a class="underline" href="{{ route('document', ['document' => $doc->id]) }}">leht</a>
                                    </div>
                                    @if($a->extraction?->summary)
                                        <div class="text-xs text-gray-600 mt-1">{{ $a->extraction->summary }}</div>
                                    @endif
                                @else
                                    <span class="text-gray-400">(dokument kustutatud)</span>
                                @endif
                            </td>
                            <td class="text-xs">
                                {{ $private }} eraisikut / {{ $subs->count() }} kokku
                                <div class="text-gray-500">
                                    @foreach($subs->take(4) as $s)
                                        <div>{{ $s->displayName() }} <span class="text-gray-400">· {{ $s->effectiveContextLabel() }}</span></div>
                                    @endforeach
                                    @if($subs->count() > 4)<div>…</div>@endif
                                </div>
                            </td>
                            <td class="text-xs">
                                @foreach($a->extraction?->flags ?? [] as $flag)
                                    <div>{{ \App\Lib\Pii\Flags::label($flag) }}</div>
                                @endforeach
                            </td>
                            <td class="text-xs whitespace-nowrap">
                                {{ $doc?->visible ? 'nähtav' : 'peidetud' }}
                                @if($doc?->redacted_at)<div class="text-green-700">redigeeritud {{ $doc->redacted_at->format('d.m.Y') }}</div>@endif
                                @if($active && $active->isInProgress())<div class="text-blue-700">redigeerimine pooleli</div>@endif
                                @if($a->isReviewed())<div class="text-gray-500">läbivaadatud · {{ $a->review_action }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap">
                                <div class="flex flex-wrap gap-1">
                                    <a href="{{ route('pii.show', $doc) }}"><x-bladewind.button size="tiny" color="blue">Vaata</x-bladewind.button></a>
                                    @if($doc && $doc->visible)
                                        <form method="post" action="{{ route('pii.hide', $doc) }}" onsubmit="return confirm('Peida dokument avalikkuse eest?')">@csrf
                                            <x-bladewind.button size="tiny" color="yellow" can_submit="true">Peida</x-bladewind.button>
                                        </form>
                                    @elseif($doc)
                                        <form method="post" action="{{ route('pii.unhide', $doc) }}">@csrf
                                            <x-bladewind.button size="tiny" color="green" can_submit="true">Näita</x-bladewind.button>
                                        </form>
                                    @endif
                                    @unless($a->isReviewed())
                                        <form method="post" action="{{ route('pii.acknowledge', $a) }}">@csrf
                                            <x-bladewind.button size="tiny" color="gray" can_submit="true">Läbivaadatud</x-bladewind.button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-gray-400 py-8">Hinnanguid pole.</td></tr>
                    @endforelse
                </x-bladewind.table>
                <div class="mt-4">{{ $assessments->links() }}</div>
            @endif
        </div>
    </div>
@endsection
