{{-- Admin panel on the document page: every admin view and action for one document. --}}
@php
    $item = $admin['item'];
    $pii = $admin['pii'];
    $assessment = $pii['assessment'];
    $sourceLabels = ['letter' => 'kiri', 'recheck' => 'allika kontroll', 'takedown' => 'eemaldamistaotlus', 'pii' => 'isikuandmete hinnang', 'haldus' => 'haldus', 'peidetud' => 'peidetud, failid avalikud'];
    $decisionLabels = ['remove' => 'eemaldatud', 'redact' => 'redigeeritud', 'keep' => 'alles jäetud'];
@endphp

<div class="text-sm space-y-4 mb-8" id="haldus">

    {{-- 1. Decision ----------------------------------------------------- --}}
    <section class="border border-gray-300 rounded p-4" id="otsus">
        <div class="flex items-center gap-2 mb-2">
            <h2 class="text-lg font-bold">Otsus</h2>
            @if($document->isRemoved())
                <x-bladewind.tag label="eemaldatud" color="red"/>
            @elseif(!$document->visible)
                <x-bladewind.tag label="peidetud" color="orange"/>
            @else
                <x-bladewind.tag label="nähtav" color="green"/>
            @endif
            @if($document->redacted_at)
                <x-bladewind.tag label="redigeeritud" color="blue"/>
            @endif
        </div>

        @if($item)
            <div class="mb-3">
                <div>
                    Ülevaatus #{{ $item->id }}:
                    @if($item->isOpen())
                        <span class="font-semibold text-orange-700">ootab otsust</span> (prioriteet {{ $item->priority }})
                    @else
                        <span class="font-semibold">{{ $decisionLabels[$item->decision] ?? $item->decision }}</span>
                        · {{ $item->decided_by }} · {{ $item->decided_at?->format('d.m.Y H:i') }}
                    @endif
                </div>
                @if($item->decision_reason)
                    <div class="text-gray-600">Põhjus: {{ $item->decision_reason }}</div>
                @endif
                <ul class="mt-1 text-gray-600">
                    @foreach($item->flags ?? [] as $flag)
                        <li>· {{ $sourceLabels[$flag['source']] ?? $flag['source'] }}
                            @if(!empty($flag['reference'])) — {{ $flag['reference'] }} @endif
                            @if(!empty($flag['note'])) <span class="text-gray-400">({{ \Illuminate\Support\Str::limit($flag['note'], 80) }})</span> @endif
                            @if(isset($flag['public_sector'])) · {{ $flag['public_sector'] ? 'avalik sektor' : 'eraisik/ettevõte' }} @endif
                            @if(!empty($flag['personal_data'])) · <span class="text-red-700">isikuandmed</span> @endif
                            <span class="text-gray-400">{{ \Illuminate\Support\Carbon::parse($flag['flagged_at'])->format('d.m.Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <p class="text-gray-500 mb-3">Dokumenti pole ülevaatusele märgitud.</p>
        @endif

        @unless($document->isRemoved())
            <form method="post" action="{{ route('document.decide', $document) }}" id="decision-form">
                @csrf
                <label class="block text-gray-600">Põhjus (kohustuslik; säilib aruandes)</label>
                <textarea name="reason" rows="3" required class="w-full border rounded p-2 mb-2">{{ old('reason') }}</textarea>
                <label class="block text-gray-600">Viide (nt AKI menetluse või kirja number)</label>
                <input name="reference" value="{{ old('reference') }}" class="w-full border rounded p-2 mb-2"/>
                <details class="mb-2">
                    <summary class="cursor-pointer text-gray-600">Redigeerimiseks: eemaldatavad tekstid (valikuline)</summary>
                    <p class="text-gray-500 mt-1">Üks rida kohta (nimed, isikukoodid, aadressid). Tühjaks jättes kasutatakse isikuandmete hinnangu plaani.</p>
                    <textarea name="strings" rows="3" class="w-full border rounded p-2">{{ old('strings') }}</textarea>
                </details>
                <input type="hidden" name="decision" id="decision-input"/>
                <div class="flex flex-wrap gap-2">
                    <x-bladewind.button size="small" color="red" can_submit="true"
                        onclick="if(!confirm('Eemaldada dokument? Failid, tekst ja isikuandmete read kustutatakse jäädavalt.')) return false; document.getElementById('decision-input').value='remove';">
                        Eemalda
                    </x-bladewind.button>
                    <x-bladewind.button size="small" color="orange" can_submit="true"
                        onclick="if(!confirm('Redigeerida isikuandmed? Tekst asendatakse kohe, failid asendatakse redigeeritud koopiatega.')) return false; document.getElementById('decision-input').value='redact';">
                        Redigeeri
                    </x-bladewind.button>
                    <x-bladewind.button size="small" color="gray" can_submit="true"
                        onclick="document.getElementById('decision-input').value='keep';">
                        Jäta alles
                    </x-bladewind.button>
                </div>
            </form>

            <div class="flex gap-2 mt-3 text-gray-600">
                @if($document->visible)
                    <form method="post" action="{{ route('pii.hide', $document) }}" onsubmit="return confirm('Peida dokument avalikkuse eest?')">@csrf
                        <button class="underline">Peida ajutiselt</button>
                    </form>
                @else
                    <form method="post" action="{{ route('pii.unhide', $document) }}">@csrf
                        <button class="underline">Näita uuesti</button>
                    </form>
                @endif
                @if($document->organisation->fetcher_type === 'delta-adr')
                    · <form method="post" action="{{ route('document.reindex', $document) }}" onsubmit="return confirm('Laadida failid allikast uuesti?')">@csrf
                        <button class="underline">Lae failid uuesti</button>
                    </form>
                @endif
            </div>
        @endunless
    </section>

    {{-- 2. Takedown requests --------------------------------------------- --}}
    <section class="border border-gray-300 rounded p-4" id="taotlused">
        <h2 class="text-lg font-bold mb-2">Eemaldamistaotlused ({{ $admin['takedowns']->count() }})</h2>
        @forelse($admin['takedowns'] as $t)
            <div class="border-t pt-2 mt-2" id="taotlus-{{ $t->id }}">
                <div>
                    <span class="font-semibold">#{{ $t->id }} {{ $t->author_name }}</span>
                    &lt;{{ $t->author_email }}&gt;
                    · {{ $t->isFromPublicSector() ? 'avalik sektor' : 'eraisik/ettevõte' }}
                    · <span class="{{ $t->isResolved() ? '' : 'text-orange-700 font-semibold' }}">{{ $t->status }}</span>
                </div>
                <div class="text-gray-600">{{ $t->legal_basis }} · esitatud {{ $t->created_at->format('d.m.Y H:i') }}
                    @if($t->verified_at) · kinnitatud {{ $t->verified_at->format('d.m.Y') }}
                        @if(!$t->isResolved() && $t->verified_at->lt(now()->subDays(30))) · <span class="text-red-700 font-semibold">üle 30 päeva</span> @endif
                    @endif
                </div>
                @if($t->objection_note)
                    <div class="mt-1 p-2 bg-gray-50 rounded">{{ $t->objection_note }}</div>
                @endif
                @if($t->isResolved())
                    <div class="text-gray-600 mt-1">Lahendatud {{ $t->resolved_at?->format('d.m.Y H:i') }}@if($t->resolution_note): {{ $t->resolution_note }}@endif</div>
                @elseif($t->status === \App\Models\TakedownRequest::STATUS_PENDING)
                    <form method="post" action="{{ route('takedowns.accept', $t) }}" id="takedown-form-{{ $t->id }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="action" id="takedown-action-{{ $t->id }}"/>
                        <label class="block text-gray-600">Vastus esitajale (kohustuslik, v.a eemaldamisel)</label>
                        <textarea name="resolution_note" rows="3" class="w-full border rounded p-2 mb-2"></textarea>
                        <details class="mb-2">
                            <summary class="cursor-pointer text-gray-600">Redigeerimiseks: eemaldatavad tekstid (valikuline)</summary>
                            <textarea name="strings" rows="2" class="w-full border rounded p-2 mt-1" placeholder="Üks rida kohta; tühjaks jättes kasutatakse isikuandmete hinnangu plaani"></textarea>
                        </details>
                        <div class="flex flex-wrap gap-2">
                            <x-bladewind.button size="small" color="red" can_submit="true"
                                onclick="if(!confirm('Rahuldada ja eemaldada dokument jäädavalt?')) return false; document.getElementById('takedown-action-{{ $t->id }}').value='remove';">
                                Rahulda ja eemalda
                            </x-bladewind.button>
                            <x-bladewind.button size="small" color="orange" can_submit="true"
                                onclick="if(!confirm('Rahuldada ja redigeerida isikuandmed?')) return false; document.getElementById('takedown-action-{{ $t->id }}').value='redact';">
                                Rahulda ja redigeeri isikuandmed
                            </x-bladewind.button>
                            <x-bladewind.button size="small" color="green" can_submit="true"
                                onclick="document.getElementById('takedown-action-{{ $t->id }}').value='none';">
                                Rahulda märkusega
                            </x-bladewind.button>
                            <x-bladewind.button size="small" color="gray" outline="true"
                                onclick="var f=document.getElementById('takedown-form-{{ $t->id }}'); f.action='{{ route('takedowns.deny', $t) }}'; f.submit();">
                                Lükka tagasi
                            </x-bladewind.button>
                        </div>
                    </form>
                @else
                    <div class="text-gray-500 mt-1">E-post on kinnitamata.</div>
                @endif
            </div>
        @empty
            <p class="text-gray-500">Taotlusi pole.</p>
        @endforelse
    </section>

    {{-- 3. Source registry ----------------------------------------------- --}}
    <section class="border border-gray-300 rounded p-4" id="allikas">
        <h2 class="text-lg font-bold mb-2">Allikas</h2>
        @if($state = $document->remoteState)
            <div>
                {{ $state->statusLabel() }}
                @if($state->remote_restriction_basis) — {{ $state->remote_restriction_basis }} @endif
                @if($state->personal_data_restriction) <span class="text-red-700 font-semibold">isikuandmed (p 12)</span> @endif
            </div>
            <div class="text-gray-500">
                @if($state->checked_at) kontrollitud {{ $state->checked_at->format('d.m.Y H:i') }} @endif
                @if($state->next_check_at) · järgmine {{ $state->next_check_at->format('d.m.Y') }} @endif
                @if($state->check_error_count) · <span class="text-orange-700">{{ $state->check_error_count }} viga järjest</span> @endif
            </div>
        @else
            <p class="text-gray-500">Ei kontrollita (allikas piiras juba kogumisel).</p>
        @endif
        @if($admin['changes']->count())
            <ul class="mt-2">
                @foreach($admin['changes'] as $c)
                    <li class="border-t py-1 flex flex-wrap items-center gap-2">
                        <span>{{ $c->occurred_at->format('d.m.Y') }} · {{ $c->transitionLabel() }}
                            @if($c->basis) <span class="text-gray-500">({{ \Illuminate\Support\Str::limit($c->basis, 60) }})</span> @endif
                            @if($c->personal_data) <span class="text-red-700">isikuandmed</span> @endif
                        </span>
                        @if($c->acknowledged_at)
                            <span class="text-gray-400">· {{ $c->action ?? 'läbi vaadatud' }} {{ $c->acknowledged_at->format('d.m.Y') }}</span>
                        @else
                            <form method="post" action="{{ route('recheck.acknowledge', $c) }}" class="inline">@csrf <button class="underline">kinnita</button></form>
                            <form method="post" action="{{ route('recheck.ignore', $c) }}" class="inline">@csrf <button class="underline">ignoreeri</button></form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 4. Personal data ------------------------------------------------- --}}
    <section class="border border-gray-300 rounded p-4" id="isikuandmed">
        <div class="flex items-center gap-2 mb-2">
            <h2 class="text-lg font-bold">Isikuandmed</h2>
            @if($assessment)
                <x-bladewind.tag :label="$assessment->bandLabel()" :color="$assessment->bandColor()"/>
                <span>{{ $assessment->recommendationLabel() }}</span>
            @endif
        </div>
        @if($pii['flagged']) <div class="text-red-700 mb-1">Allikas piiras isikuandmete tõttu.</div> @endif

        <div class="flex flex-wrap gap-3 mb-2">
            <form method="post" action="{{ route('pii.extract', $document) }}">@csrf
                <button class="underline text-blue-700">{{ $pii['extraction'] ? 'Uuenda ekstraktsiooni' : 'Kontrolli isikuandmeid' }}</button>
            </form>
            @if($assessment && !$assessment->isReviewed())
                <form method="post" action="{{ route('pii.acknowledge', $assessment) }}">@csrf
                    <button class="underline">Märgi hinnang läbivaadatuks</button>
                </form>
            @endif
        </div>

        @if(($la = $pii['latestAny']) && !in_array($la->status, ['done', 'too_large'], true))
            <div class="mb-2 {{ $la->status === 'failed' ? 'text-red-700' : 'text-gray-600' }}">
                Ekstraktsioon: {{ $la->statusLabel() }} @if($la->error) · {{ $la->error }} @endif
                @if($la->status === 'failed')
                    <form method="post" action="{{ route('pii.retry', $la) }}" class="inline">@csrf <button class="underline">proovi uuesti</button></form>
                @endif
            </div>
        @endif

        @if($ex = $pii['extraction'])
            <details class="mb-2">
                <summary class="cursor-pointer text-gray-600">Dokument mudeli järgi</summary>
                <div class="mt-1 space-y-1">
                    <div><span class="text-gray-500">Asi:</span> {{ $ex->matter }}</div>
                    <div><span class="text-gray-500">Kokkuvõte:</span> {{ $ex->summary }}</div>
                    <div><span class="text-gray-500">Avalik huvi:</span> {{ $ex->public_interest }} — {{ $ex->public_interest_reason }}</div>
                    <div><span class="text-gray-500">Märgised:</span>
                        @forelse($ex->flags ?? [] as $flag) <span class="inline-block border rounded px-1 mr-1">{{ $pii['flagLabels'][$flag] ?? $flag }}</span> @empty — @endforelse
                    </div>
                    @if($assessment)
                        <div><span class="text-gray-500">Reeglid:</span>
                            @foreach($assessment->fired_rules ?? [] as $rule) <div title="{{ $rule['id'] }}">· {{ $rule['label'] }}</div> @endforeach
                        </div>
                        <div class="text-gray-400">reeglid v{{ $assessment->rules_version }} · {{ $assessment->computed_at->format('d.m.Y H:i') }}
                            @if($pii['staleAssessment']) · <span class="text-orange-700">reeglid muutunud; arvutatakse uuesti</span> @endif
                            @if($assessment->isReviewed()) · läbivaadatud ({{ $assessment->review_action }}) @endif
                        </div>
                    @endif
                    <div class="text-gray-400">{{ $ex->model }} · v{{ $ex->prompt_version }} · {{ number_format($ex->input_tokens + $ex->output_tokens) }} tokenit · {{ $ex->finished_at?->format('d.m.Y H:i') }}</div>
                </div>
            </details>

            @foreach($pii['subjects'] as $s)
                @php $act = $assessment?->actionsFor($s->id); @endphp
                <div class="border-t py-2 {{ $s->isKeep() ? '' : 'bg-yellow-50' }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ $s->displayName() }}</span>
                        @if($s->personal_code) <span class="font-mono text-xs">{{ $s->personal_code }}</span> @endif
                        <span class="text-gray-500">{{ $s->role }} {{ $s->organisation }}</span>
                        <form method="post" action="{{ route('pii.override', $s) }}" class="ml-auto">@csrf
                            <select name="context_override" class="border rounded px-1 py-1 text-xs max-w-[200px]" onchange="this.form.submit()">
                                @foreach($pii['contexts'] as $k => $l)
                                    <option value="{{ $k }}" @selected($s->effectiveContext() === $k)>{{ $l }}</option>
                                @endforeach
                            </select>
                            <input type="hidden" name="override_note" value="{{ $s->override_note }}">
                        </form>
                    </div>
                    @if($s->context_override)<div class="text-xs text-orange-700">muudetud (mudel: {{ $pii['contexts'][$s->context] ?? $s->context }})</div>@endif
                    <div class="text-xs text-gray-500">
                        @foreach($s->surface_forms ?? [] as $f) <span class="font-mono">{{ $f['text'] }}</span>@if(!$loop->last), @endif @endforeach
                        @foreach($s->identifiers ?? [] as $id) · <span class="{{ empty($id['verified']) ? 'line-through text-red-600' : '' }}">{{ $id['type'] }} {{ $id['value'] }}</span> @endforeach
                    </div>
                    @if($act)
                        <div class="text-xs text-gray-500">nimi: {{ $act['name'] }} · isikukood: {{ $act['personal_code'] }} · erakontakt: {{ $act['private_contacts'] }} · töökontakt: {{ $act['work_contacts'] }}</div>
                    @endif
                </div>
            @endforeach
        @elseif(!$pii['latestAny'])
            <p class="text-gray-500">Ekstraktsiooni pole tehtud.</p>
        @endif

        @if($active = $pii['active'])
            <div class="border-t pt-2 mt-2">
                <div class="font-semibold">Kehtiv redigeerimine #{{ $active->id }}</div>
                <div class="text-gray-600">tekst: {{ \App\Models\PiiRedaction::statusLabel($active->text_status) }} · failid: {{ \App\Models\PiiRedaction::statusLabel($active->files_status) }} · {{ $active->applied_by }} {{ $active->applied_at?->format('d.m.Y H:i') }}</div>
                @foreach($active->plan['files'] ?? [] as $fid => $fa)
                    @php $file = $document->files->firstWhere('id', (int)$fid); @endphp
                    <div class="text-xs">{{ $fa['name'] }} · {{ $fa['action'] }}
                        @if($file?->redacted_location) · <a class="underline" href="{{ $file->url }}">redigeeritud koopia</a> @endif
                        @if($file?->original_private_location) · originaal privaatses hoidlas @endif
                    </div>
                @endforeach
                @unless($active->isInProgress())
                    <div class="flex gap-3 mt-2">
                        @if(in_array($active->files_status, [\App\Models\PiiRedaction::STATUS_FAILED, \App\Models\PiiRedaction::STATUS_PARTIAL], true))
                            <form method="post" action="{{ route('pii.retryFiles', $active) }}">@csrf <button class="underline text-blue-700">Proovi faile uuesti</button></form>
                        @endif
                        <form method="post" action="{{ route('pii.revert', $active) }}" onsubmit="return confirm('Taasta algne tekst ja failid?')">@csrf <button class="underline text-red-700">Taasta</button></form>
                    </div>
                @endunless
            </div>
        @elseif($plan = $pii['plan'])
            <div class="border-t pt-2 mt-2">
                <div class="font-semibold">Redigeerimise plaan (eelvaade; rakendub nupuga „Redigeeri“)</div>
                @if(empty($plan['replacements']))
                    <div class="text-gray-500">Hinnangu järgi pole midagi asendada.</div>
                @else
                    @foreach($plan['replacements'] as $r)
                        <div class="text-xs"><span class="font-mono">{{ $r['surface'] }}</span> → <span class="font-mono">{{ $r['replacement'] }}</span></div>
                    @endforeach
                    <div class="mt-1">
                        @foreach($plan['files'] as $fa)
                            <div class="text-xs">{{ $fa['name'] }} · <span class="font-semibold">{{ $fa['action'] }}</span> · {{ $fa['reason'] }}</div>
                        @endforeach
                    </div>
                    @if($pii['preview'])
                        <details class="mt-1"><summary class="cursor-pointer text-gray-600">Enne/pärast ({{ count($pii['preview']) }})</summary>
                            @foreach($pii['preview'] as $p)
                                <div class="border-t py-1 text-xs">
                                    <div class="text-gray-400">{{ $p['file'] }}</div>
                                    <div class="text-red-800">…{{ $p['before'] }}…</div>
                                    <div class="text-green-800">…{{ $p['after'] }}…</div>
                                </div>
                            @endforeach
                        </details>
                    @endif
                @endif
            </div>
        @endif
    </section>

    {{-- 5. Log ------------------------------------------------------------ --}}
    <section class="border border-gray-300 rounded p-4" id="logi">
        <h2 class="text-lg font-bold mb-2">Logi ({{ $admin['log']->count() }})</h2>
        @forelse($admin['log'] as $a)
            <div class="border-t py-1 text-xs">
                <span class="text-gray-500">{{ $a->created_at?->format('d.m.Y H:i') }}</span>
                · <span class="font-semibold">{{ $a->effect }}</span>
                · {{ $a->actor }}
                @if($a->result)
                    <details class="inline"><summary class="inline cursor-pointer text-gray-500">andmed</summary>
                        <pre class="whitespace-pre-wrap">{{ json_encode($a->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            </div>
        @empty
            <p class="text-gray-500">Toiminguid pole.</p>
        @endforelse
    </section>
</div>
