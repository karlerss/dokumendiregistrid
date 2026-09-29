@extends('layout')

@section('title', 'Isikuandmed: ' . $document->title)

@section('content')
    <div class="px-4">
        <div class="w-full">
            <div class="flex items-center mb-4 gap-4">
                <h1 class="text-2xl font-bold">Isikuandmed: {{ \Illuminate\Support\Str::limit($document->title, 100) }}</h1>
                @if($assessment)
                    <x-bladewind.tag :label="$assessment->bandLabel()" :color="$assessment->bandColor()"/>
                @endif
                <a href="{{ route('pii.index') }}" class="ml-auto text-blue-500 hover:underline">Tagasi nimekirja</a>
            </div>

            @if(session('success'))
                <div class="mb-4"><x-bladewind.alert type="success">{{ session('success') }}</x-bladewind.alert></div>
            @endif
            @if(session('error'))
                <div class="mb-4"><x-bladewind.alert type="error">{{ session('error') }}</x-bladewind.alert></div>
            @endif

            <div class="text-sm text-gray-600 mb-4">
                {{ $document->organisation->name }} · {{ $document->reference }} · {{ $document->registration_date->format('d.m.Y') }} · {{ $document->type }}
                · {{ $document->series }}
                · <a class="underline" href="{{ route('document', ['document' => $document->id]) }}">dokumendi leht</a>
                · <a class="underline" target="_blank" rel="noopener" href="{{ $document->url }}">allikas</a>
                · {{ $document->visible ? 'nähtav' : 'peidetud' }}
                @if($flagged) · <span class="text-red-700">allikas piiras isikuandmete tõttu</span> @endif
                @if($document->redacted_at) · <span class="text-green-700">redigeeritud {{ $document->redacted_at->format('d.m.Y H:i') }}</span> @endif
            </div>

            {{-- Actions --}}
            <div class="flex flex-wrap gap-2 mb-6">
                <form method="post" action="{{ route('pii.extract', $document) }}">@csrf
                    <x-bladewind.button size="small" color="blue" can_submit="true">{{ $extraction ? 'Uuenda ekstraktsiooni' : 'Kontrolli isikuandmeid' }}</x-bladewind.button>
                </form>
                @if($document->visible)
                    <form method="post" action="{{ route('pii.hide', $document) }}" onsubmit="return confirm('Peida dokument avalikkuse eest?')">@csrf
                        <x-bladewind.button size="small" color="yellow" can_submit="true">Peida</x-bladewind.button>
                    </form>
                @else
                    <form method="post" action="{{ route('pii.unhide', $document) }}">@csrf
                        <x-bladewind.button size="small" color="green" can_submit="true">Näita</x-bladewind.button>
                    </form>
                @endif
                @if($assessment && !$assessment->isReviewed())
                    <form method="post" action="{{ route('pii.acknowledge', $assessment) }}">@csrf
                        <x-bladewind.button size="small" color="gray" can_submit="true">Märgi läbivaadatuks</x-bladewind.button>
                    </form>
                @endif
            </div>

            {{-- Extraction state --}}
            @if($latestAny && $latestAny->status !== 'done' && $latestAny->status !== 'too_large')
                <div class="mb-6">
                    <x-bladewind.alert type="{{ $latestAny->status === 'failed' ? 'error' : 'info' }}" shade="faint">
                        Ekstraktsioon: {{ $latestAny->statusLabel() }}
                        @if($latestAny->error) · {{ $latestAny->error }} @endif
                        @if($latestAny->status === 'failed')
                            <form method="post" action="{{ route('pii.retry', $latestAny) }}" class="inline">@csrf <button class="underline">proovi uuesti</button></form>
                        @endif
                    </x-bladewind.alert>
                </div>
            @endif

            @if(!$extraction)
                <x-bladewind.alert type="info" shade="faint">Ekstraktsiooni ei ole veel tehtud.</x-bladewind.alert>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 text-sm">
                    <div class="border border-gray-200 rounded p-4">
                        <div class="font-semibold mb-2">Dokument mudeli järgi</div>
                        <div><span class="text-gray-500">Asi:</span> {{ $extraction->matter }}</div>
                        <div><span class="text-gray-500">Kokkuvõte:</span> {{ $extraction->summary }}</div>
                        <div><span class="text-gray-500">Avalik huvi:</span> {{ $extraction->public_interest }} — {{ $extraction->public_interest_reason }}</div>
                        <div><span class="text-gray-500">Märgised:</span>
                            @forelse($extraction->flags ?? [] as $flag)
                                <span class="inline-block border rounded px-1 mr-1 {{ \App\Lib\Pii\Flags::kind($flag) === 'reid' ? 'border-red-300' : (\App\Lib\Pii\Flags::kind($flag) === 'sensitivity' ? 'border-yellow-400' : 'border-gray-300') }}" title="{{ $flag }}">{{ $flagLabels[$flag] ?? $flag }}</span>
                            @empty — @endforelse
                        </div>
                        @if($extraction->raw_response['restriction_stamp_basis'] ?? null)
                            <div><span class="text-gray-500">AK-märge tekstis:</span> {{ $extraction->raw_response['restriction_stamp_holder'] ?? '' }} · {{ $extraction->raw_response['restriction_stamp_basis'] }}</div>
                        @endif
                        <div><span class="text-gray-500">Juriidilised isikud:</span>
                            {{ collect($extraction->legal_entities ?? [])->map(fn($e) => $e['name'] . ($e['registry_code'] ? " ({$e['registry_code']})" : ''))->implode(', ') ?: '—' }}
                        </div>
                        <div class="text-gray-400 mt-2">
                            {{ $extraction->model }} · prompt v{{ $extraction->prompt_version }} · {{ $extraction->chunks }} osa · {{ number_format($extraction->input_tokens + $extraction->output_tokens) }} tokenit · {{ $extraction->finished_at?->format('d.m.Y H:i') }}
                            @if($extraction->status === 'too_large') · <span class="text-orange-700">sisend kärbitud</span> @endif
                            @if(count($extraction->raw_response['dropped_subjects'] ?? [])) · {{ count($extraction->raw_response['dropped_subjects']) }} kinnitamata isikut jäeti kõrvale @endif
                        </div>
                    </div>
                    <div class="border border-gray-200 rounded p-4">
                        <div class="font-semibold mb-2">Hinnang</div>
                        @if($assessment)
                            <div class="mb-1"><x-bladewind.tag :label="$assessment->bandLabel()" :color="$assessment->bandColor()"/> {{ $assessment->recommendationLabel() }}</div>
                            <ul class="list-disc list-inside">
                                @foreach($assessment->fired_rules ?? [] as $rule)
                                    <li title="{{ $rule['id'] }}">{{ $rule['label'] }}
                                        @if(!empty($rule['inputs']))
                                            <span class="text-gray-400">{{ \Illuminate\Support\Str::limit(json_encode($rule['inputs'], JSON_UNESCAPED_UNICODE), 160) }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <div class="text-gray-400 mt-2">
                                reeglid v{{ $assessment->rules_version }} · {{ $assessment->computed_at->format('d.m.Y H:i') }}
                                @if($staleAssessment) · <span class="text-orange-700">reeglid on vahepeal muutunud; töötleja arvutab uuesti</span> @endif
                                @if($assessment->isReviewed()) · läbivaadatud {{ $assessment->reviewed_at->format('d.m.Y H:i') }} ({{ $assessment->review_action }}) @endif
                            </div>
                        @else
                            <div class="text-gray-500">hindamine ootel</div>
                        @endif
                    </div>
                </div>

                {{-- Subjects --}}
                <h2 class="text-xl font-bold mb-2">Isikud</h2>
                <x-bladewind.table divider="thin">
                    <x-slot name="header">
                        <th>Isik</th>
                        <th>Kontekst</th>
                        <th>Roll / asutus</th>
                        <th>Leitud kujud</th>
                        <th>Tunnused</th>
                        <th>Märgised</th>
                        <th>Tegevus</th>
                    </x-slot>
                    @foreach($subjects as $s)
                        @php $act = $assessment?->actionsFor($s->id); @endphp
                        <tr class="{{ $s->isKeep() ? '' : 'bg-yellow-50' }}">
                            <td class="text-sm">
                                <div class="font-semibold">{{ $s->displayName() }}</div>
                                @if($s->personal_code)<div class="font-mono text-xs">{{ $s->personal_code }}</div>@endif
                                @if($s->linked_metadata_initials)<div class="text-xs text-gray-500">registris: {{ $s->linked_metadata_initials }}</div>@endif
                                <div class="text-xs text-gray-400 mt-1">„{{ \Illuminate\Support\Str::limit($s->evidence, 160) }}“ · {{ $s->confidence }}</div>
                            </td>
                            <td class="text-sm">
                                <form method="post" action="{{ route('pii.override', $s) }}">@csrf
                                    <select name="context_override" class="border rounded px-1 py-1 text-xs max-w-[220px]" onchange="this.form.submit()">
                                        @foreach($contexts as $k => $l)
                                            <option value="{{ $k }}" @selected($s->effectiveContext() === $k)>{{ $l }}</option>
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="override_note" value="{{ $s->override_note }}">
                                </form>
                                @if($s->context_override)
                                    <div class="text-xs text-orange-700">muudetud (mudel: {{ $contexts[$s->context] ?? $s->context }})</div>
                                @endif
                            </td>
                            <td class="text-xs">{{ $s->role }}<br><span class="text-gray-500">{{ $s->organisation }}</span></td>
                            <td class="text-xs">
                                @foreach($s->surface_forms ?? [] as $f)
                                    <div><span class="font-mono">{{ $f['text'] }}</span> <span class="text-gray-400">{{ implode(', ', array_map(fn($i) => explode(':', $i)[0], $f['in'])) }}</span></div>
                                @endforeach
                                @foreach($s->unverified_forms ?? [] as $f)
                                    <div class="text-red-600 line-through" title="mudel pakkus, tekstis ei leidu">{{ $f }}</div>
                                @endforeach
                            </td>
                            <td class="text-xs">
                                @foreach($s->identifiers ?? [] as $id)
                                    <div class="{{ empty($id['verified']) ? 'text-red-600 line-through' : '' }}">{{ $id['type'] }} · {{ $id['value'] }} <span class="text-gray-400">{{ $id['nature'] }}</span></div>
                                @endforeach
                            </td>
                            <td class="text-xs">
                                @foreach($s->subject_flags ?? [] as $flag)
                                    <div>{{ $flagLabels[$flag] ?? $flag }}</div>
                                @endforeach
                            </td>
                            <td class="text-xs">
                                @if($act)
                                    nimi: {{ $act['name'] }}<br>
                                    isikukood: {{ $act['personal_code'] }}<br>
                                    erakontakt: {{ $act['private_contacts'] }}<br>
                                    töökontakt: {{ $act['work_contacts'] }}<br>
                                    kinnistud: {{ $act['property_ids'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-bladewind.table>

                {{-- Redaction --}}
                <h2 class="text-xl font-bold mt-8 mb-2">Redigeerimine</h2>

                @if($active)
                    <div class="border border-gray-200 rounded p-4 text-sm mb-4">
                        <div class="mb-2">
                            <span class="font-semibold">Kehtiv redigeerimine #{{ $active->id }}</span>
                            · tekst: {{ \App\Models\PiiRedaction::statusLabel($active->text_status) }}
                            · failid: {{ \App\Models\PiiRedaction::statusLabel($active->files_status) }}
                            · {{ $active->applied_by }} {{ $active->applied_at?->format('d.m.Y H:i') }}
                        </div>
                        <div class="text-xs mb-2">
                            @foreach($active->plan['files'] ?? [] as $fid => $fa)
                                @php $file = $document->files->firstWhere('id', (int)$fid); @endphp
                                <div>{{ $fa['name'] }} · {{ $fa['action'] }} · {{ $fa['reason'] }}
                                    @if($file?->redacted_location) · <a class="underline" href="{{ $file->url }}">redigeeritud koopia</a> @endif
                                    @if($file?->original_private_location) · originaal privaatses hoidlas @endif
                                </div>
                            @endforeach
                        </div>
                        <details class="text-xs"><summary class="cursor-pointer">logi</summary>
                            <pre class="whitespace-pre-wrap">{{ json_encode($active->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                        @unless($active->isInProgress())
                            <div class="flex gap-2 mt-3">
                                @if(in_array($active->files_status, [\App\Models\PiiRedaction::STATUS_FAILED, \App\Models\PiiRedaction::STATUS_PARTIAL], true))
                                    <form method="post" action="{{ route('pii.retryFiles', $active) }}">@csrf
                                        <x-bladewind.button size="small" color="blue" can_submit="true">Proovi faile uuesti</x-bladewind.button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('pii.revert', $active) }}" onsubmit="return confirm('Taasta algne tekst ja failid?')">@csrf
                                    <x-bladewind.button size="small" color="red" can_submit="true">Taasta</x-bladewind.button>
                                </form>
                            </div>
                        @endunless
                    </div>
                @elseif($plan)
                    <div class="border border-gray-200 rounded p-4 text-sm mb-4">
                        <div class="font-semibold mb-2">Plaan (eelvaade, midagi pole veel muudetud)</div>
                        @if(empty($plan['replacements']))
                            <div class="text-gray-500">Kehtiva hinnangu järgi pole midagi asendada.</div>
                        @else
                            <div class="mb-3">
                                <div class="text-gray-500 mb-1">Asendused</div>
                                @foreach($plan['replacements'] as $r)
                                    <div><span class="font-mono">{{ $r['surface'] }}</span> → <span class="font-mono">{{ $r['replacement'] }}</span> <span class="text-gray-400">{{ $r['kind'] }}</span></div>
                                @endforeach
                            </div>
                            <div class="mb-3">
                                <div class="text-gray-500 mb-1">Failid</div>
                                @foreach($plan['files'] as $fa)
                                    <div>{{ $fa['name'] }} · <span class="font-semibold">{{ $fa['action'] }}</span> · {{ $fa['reason'] }}</div>
                                @endforeach
                                @if($plan['clear_ai_summary'])<div class="text-gray-500">AI kokkuvõte tühjendatakse.</div>@endif
                            </div>
                            @if($preview)
                                <details class="mb-3"><summary class="cursor-pointer text-gray-500">Näidised enne/pärast ({{ count($preview) }})</summary>
                                    @foreach($preview as $p)
                                        <div class="border-t py-2 text-xs">
                                            <div class="text-gray-400">{{ $p['file'] }} · {{ $p['surface'] }}</div>
                                            <div class="text-red-800">…{{ $p['before'] }}…</div>
                                            <div class="text-green-800">…{{ $p['after'] }}…</div>
                                        </div>
                                    @endforeach
                                </details>
                            @endif
                            <form method="post" action="{{ route('pii.redact', $document) }}" onsubmit="return confirm('Rakenda redigeerimine? Tekst asendatakse kohe, originaalfailid tõstetakse privaatsesse hoidlasse ja asendatakse redigeeritud koopiatega.')">@csrf
                                <x-bladewind.button size="small" color="red" can_submit="true">Rakenda redigeerimine</x-bladewind.button>
                            </form>
                        @endif
                    </div>
                @endif

                @if($redactions->count())
                    <details class="text-xs text-gray-500"><summary class="cursor-pointer">Redigeerimiste ajalugu ({{ $redactions->count() }})</summary>
                        @foreach($redactions as $r)
                            <div>#{{ $r->id }} · {{ $r->created_at->format('d.m.Y H:i') }} · tekst {{ \App\Models\PiiRedaction::statusLabel($r->text_status) }} · failid {{ \App\Models\PiiRedaction::statusLabel($r->files_status) }}
                                @if($r->reverted_at) · taastatud {{ $r->reverted_at->format('d.m.Y H:i') }} @endif
                                @if($r->text_status === 'failed')
                                    <details class="inline"><summary class="inline cursor-pointer underline">viga</summary><pre class="whitespace-pre-wrap">{{ json_encode($r->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>
                                @endif
                            </div>
                        @endforeach
                    </details>
                @endif
            @endif
        </div>
    </div>
@endsection
