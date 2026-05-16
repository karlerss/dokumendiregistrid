@extends('layout')

@section('content')
    <div class="container mx-auto">
        <h1 class="text-2xl font-bold mb-4">Toimik: {{$documents->first()?->dossier}}</h1>
    </div>
    <div class="container mx-auto">
        <x-bladewind.table divider="thin">
            <x-slot name="header">
                <th>Info</th>
                <th>Tüüp</th>
                <th>Osapooled</th>
            </x-slot>
            @foreach($documents as $doc)
                <tr>
                    <td style="width: 40vw;">
                        <div class="mb-2">
                            <a href="{{route('document', ['document' => $doc->id, 'slug' => \Illuminate\Support\Str::slug($doc->ai_title ?? $doc->title)])}}"
                                class="font-bold underline"
                            >{{$doc->ai_title ?? $doc->title}}</a>
                        </div>
                        <div class="text-sm ai-summary">
                            {!! clean($doc->ai_summary, 'ai_summary') !!}
                        </div>
                    </td>
                    <td style="white-space: nowrap;">{{$doc->type}}</td>
                    <td>{{$doc->to}}</td>
                </tr>
            @endforeach
        </x-bladewind.table>
    </div>
@stop
