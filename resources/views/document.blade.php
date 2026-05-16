@extends('layout')

@section('title', "$document->title - {$document->organisation->name}")

@section('description', $document->ai_summary ? strip_tags($document->ai_summary) : null)

@section('head')
    <link rel="canonical" href="{{route('document', ['document' => $document->id, 'slug' => \Illuminate\Support\Str::slug($document->title)])}}" />
@endsection

@section('content')
    <div class="px-4">
        <div class="container mx-auto bg-white mb-8">
            <h1 class="text-3xl mb-4 font-bold">{{ $document->title }}</h1>
            @if(session('is_admin'))
                <div class="flex">
                    <form action="{{route('document.destroy', $document)}}" method="post" class="mr-2">
                        @method('DELETE')
                        @csrf
                        <x-bladewind.button size="tiny" can_submit="true">
                            <x-bladewind.icon name="trash"/>
                        </x-bladewind.button>
                    </form>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <div class="md:col-span-1 col-span-2">
                    <x-bladewind.table compact="true" divider="thin" hover_effect="false">
                        <tr>
                            <td class="text-right">Dokumendiregister</td>
                            <td class="!text-gray-900">{{ $document->organisation->name }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Viit</td>
                            <td class="!text-gray-900">{{ $document->reference }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Registreeritud</td>
                            <td class="!text-gray-900">{{ $document->registration_date->format('d.m.Y') }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Sünkroonitud</td>
                            <td class="!text-gray-900">{{ $document->created_at->format('d.m.Y') }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Liik</td>
                            <td class="!text-gray-900">{{ $document->type }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Funktsioon</td>
                            <td class="!text-gray-900">{{ $document->function }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Sari</td>
                            <td class="!text-gray-900">{{ $document->series }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Toimik</td>
                            <td class="!text-gray-900">
                                {{ $document->dossier }}
                            </td>
                        </tr>
                        <tr>
                            <td class="text-right">Juurdepääsupiirang</td>
                            <td class="!text-gray-900">{{ $document->restriction }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Adressaat</td>
                            <td class="!text-gray-900">{{ $document->to }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Saabumis/saatmisviis</td>
                            <td class="!text-gray-900">{{ $document->to }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Vastutaja</td>
                            <td class="!text-gray-900">{{ $document->responsible }}</td>
                        </tr>
                        <tr>
                            <td class="text-right">Originaal</td>
                            <td class="!text-gray-900">
                                <a target="_blank"
                                   class="text-blue-500 hover:underline cursor-pointer"
                                   href="{{$document->url}}"
                                >Ava uues aknas</a>
                            </td>
                        </tr>
                    </x-bladewind.table>
                </div>
                <div class="md:col-span-1 col-span-2">
                    @if($document->ai_title)
                        <div class="ai-summary">
                            <div class="text-sm text-gray-400">AI kokkuvõte</div>
                            <div class="text-lg font-bold mb-2">{{$document->ai_title}}</div>
                            <div class="">{!! clean($document->ai_summary, 'ai_summary') !!}</div>
                        </div>
                    @elseif(session('is_admin'))
                        @php($summaryContentLength = strlen($document->getContentsForSummary()))
                        @if($summaryContentLength < 16000 && $summaryContentLength > 100)
                            <div class="flex justify-center">
                                <form action="{{route('summarize', $document->id)}}" id="summarize-form" method="post">
                                    @csrf
                                    <x-bladewind.button
                                        size="small"
                                        has_spinner="true"
                                        can_submit="true"
                                        id="summarize"
                                        name="summarize"
                                        onclick="document.querySelector('#summarize-form').submit();document.querySelector('#summarize').disabled = true; document.querySelector('#summarize').classList.add('cursor-not-allowed'); document.querySelector('#summarize').classList.add('disabled');"
                                    >
                                        Loo AI kokkvõte
                                    </x-bladewind.button>
                                </form>
                            </div>
                        @elseif(strlen($document->getContentsForSummary()) < 10)
                            <x-bladewind.alert>
                                Puudub kokkuvõetav sisu
                            </x-bladewind.alert>
                        @else
                            <x-bladewind.alert>Dokument on kokkuvõtte tegemiseks liiga mahukas
                                ({{mb_strlen($document->getContentsForSummary())}} tm).
                            </x-bladewind.alert>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <hr class="mb-4">
        @if($document->files->count())
            <div class="container mx-auto">
                <h2 class="text-2xl font-bold mb-4">Failid</h2>
            </div>
            <div class="grid gap-4 grid-cols-4">
                <div class="md:col-span-1 col-span-4">
                    <div class="file-tree p-2 pl-0 pt-0">
                        @foreach($document->files->whereNull('parent_id') as $file)
                            <x-file-list-item
                                :file="$file"
                                :parent="null"
                                :depth="0"
                            ></x-file-list-item>
                        @endforeach
                    </div>
                </div>
                <div class="md:col-span-3 col-span-4">
                    @foreach($document->files as $file)
                        <div data-file-id="{{$file->id}}" class="hidden">
                            <x-bladewind.card>
                                <x-slot:header>
                                    <div class="flex px-4 pt-5 pb-3">
                                        <div
                                            class="uppercase tracking-wide text-xs text-gray-500/90 mb-2">
                                            <a
                                                class="underline"
                                                href="{{$file->url}}">{{$file->name}}</a>
                                        </div>
                                        <div class="uppercase tracking-wide text-xs text-gray-500/90 mb-2 ml-auto flex items-center">
                                            <button
                                                class="mr-4 font-bold uppercase tracking-wide"
                                                id="switch-preview-{{$file->id}}">Eelvaade
                                            </button>
                                            <button
                                                class="mr-4 uppercase tracking-wide"
                                                id="switch-text-{{$file->id}}"
                                            >Tekst
                                            </button>
                                            @if(session('is_admin'))
                                                <form action="{{route('file.replace', $file)}}" method="post" enctype="multipart/form-data" style="display: inline;">
                                                    @csrf
                                                    <label class="cursor-pointer inline-flex items-center px-2 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600">
                                                        <x-bladewind.icon name="arrow-path"/>
                                                        <input type="file" name="file" class="hidden" onchange="if(confirm('Replace this file?')) this.form.submit();">
                                                    </label>
                                                </form>
                                                <form action="{{route('file.destroy', $file)}}" method="post" style="display: inline;">
                                                    @method('DELETE')
                                                    @csrf
                                                    <x-bladewind.button
                                                        size="tiny"
                                                        can_submit="true"
                                                        color="red"
                                                        onclick="return confirm('Are you sure you want to delete this file?');"
                                                    >
                                                        <x-bladewind.icon name="trash"/>
                                                    </x-bladewind.button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </x-slot:header>
                                <div class="p-4 pt-2" style="min-height: 90vh">
                                    <div class="primary-content" id="preview-{{$file->id}}">
                                        @switch($file->parsed_with)
                                            @case(\App\Lib\Parser\AsiceParser::class)
                                                <h3 class="text-bold mb-3">Digiallkirjad</h3>
                                                <div class="mb-2 text-gray-400">Allkirjade kehtivust ei ole
                                                    kontrollitud.
                                                </div>
                                                @foreach($file->signatures as $signature)
                                                    <div>
                                                        <strong class="mr-2">{{$signature->name}}</strong>
                                                        <span class="mr-2">{{$signature->pno}}</span>
                                                        <span class="mr-2">{{$signature->signing_time}}</span>
                                                    </div>
                                                @endforeach

                                                @break
                                            @case(\App\Lib\Parser\PdfParser::class)
                                                <iframe src="{{$file->url}}" style="width: 100%; height: 90vh"></iframe>
                                                @break
                                            @case(\App\Lib\Parser\DocxParser::class)
                                                <iframe
                                                    src="https://view.officeapps.live.com/op/view.aspx?src={!! urlencode($file->url) !!}"
                                                    style="width: 100%; height: 90vh"></iframe>
                                                @break
                                            @case(\App\Lib\Parser\EmlParser::class)
                                            @case(\App\Lib\Parser\RtfParser::class)
                                            @case(\App\Lib\Parser\MsgParser::class)
                                                @if($file->html)
                                                    {!! clean($file->html, 'document_html') !!}
                                                @else
                                                    {!! nl2br(e($file->contents)) !!}
                                                @endif
                                                @break
                                            @default
                                                @if($file->isImage())
                                                    <img src="{{$file->url}}" alt="{{$file->name}}">
                                                @elseif($file->getExtension() === 'xlsx')
                                                    <iframe
                                                        src="https://view.officeapps.live.com/op/view.aspx?src={!! urlencode($file->url) !!}"
                                                        style="width: 100%; height: 90vh"></iframe>
                                                @else
                                                    <pre style="white-space: pre-wrap;">{{$file->contents}}</pre>
                                                @endif
                                                @break
                                        @endswitch
                                    </div>
                                    <div class="secondary-content" id="text-{{$file->id}}">
                                        @switch($file->parsed_with)
                                            @case(\App\Lib\Parser\PdfParser::class)
                                                {!! clean($file->html, 'document_html') !!}
                                                @break
                                            @case(\App\Lib\Parser\DocxParser::class)
                                                {!! nl2br(e($file->contents)) !!}
                                                @break
                                        @endswitch
                                    </div>
                                </div>
                            </x-bladewind.card>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif($document->restriction === 'Avalik' && session('is_admin'))
            <div class="container mx-auto mb-8">
                <form action="{{route('document.reindex', $document)}}" method="post" id="reindex">
                    @csrf
                    <x-bladewind.button
                        size="tiny"
                        can_submit="true"
                        id="reindex-btn"
                        onclick="document.querySelector('#reindex').submit();document.querySelector('#reindex-btn').disabled = true; document.querySelector('#reindex-btn').classList.add('cursor-not-allowed'); document.querySelector('#reindex-btn').classList.add('disabled');"
                    >
                        <x-bladewind.icon
                            name="arrow-path"/>
                        Lae failid uuesti
                    </x-bladewind.button>
                </form>
            </div>
        @endif
        @if($document->topic)
            <div class="container mx-auto mt-8">
                <h2 class="text-2xl font-bold mb-4">Seosed</h2>
            </div>
            <x-docs-table
                :documents="$document->topic->documents->filter(fn ($d) => $d->id !== $document->id)"
            ></x-docs-table>
        @endif
    </div>

@stop


@section('script')
    <script>
        function showFile(id) {
            document.querySelectorAll('[data-file-id]').forEach((el) => el.classList.add('hidden'));
            document.querySelector('[data-file-id="' + id + '"]').classList.remove('hidden');
            document.querySelectorAll('[data-file-list-id]').forEach((el) => el.classList.remove('font-bold'));
            document.querySelector('[data-file-list-id="' + id + '"]').classList.add('font-bold');
        }

        function showPreview(id) {
            document.querySelector('#switch-text-' + id).classList.remove('font-bold');
            document.querySelector('#switch-preview-' + id).classList.add('font-bold');
            document.querySelector('#text-' + id).classList.add('hidden');
            document.querySelector('#preview-' + id).classList.remove('hidden');
        }

        function showText(id) {
            document.querySelector('#switch-preview-' + id).classList.remove('font-bold');
            document.querySelector('#switch-text-' + id).classList.add('font-bold');
            document.querySelector('#preview-' + id).classList.add('hidden');
            document.querySelector('#text-' + id).classList.remove('hidden');
        }

        @php($firstFileId = $document->files->whereNotNull('contents')->first()?->id)
        @if($firstFileId)
        document.addEventListener('DOMContentLoaded', () => {
            showFile({{ $document->files->whereNotNull('contents')->first()->id }});
        });
        @endif
        document.addEventListener('DOMContentLoaded', () => {
            @foreach($document->files as $file)
            document.querySelector('#switch-preview-{{$file->id}}').addEventListener('click', () => {
                showPreview({{$file->id}});
            });
            document.querySelector('#switch-text-{{$file->id}}').addEventListener('click', () => {
                showText({{$file->id}});
            });
            showPreview({{$file->id}});

            if (document.querySelector('#text-{{$file->id}}').textContent.trim() === '') {
                document.querySelector('#switch-text-{{$file->id}}').classList.add('hidden');
            }
            @endforeach
        });
    </script>
@endsection
