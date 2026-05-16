@extends('layout')

@section('title', $organisation->name . ' - ' . $year . ' dokumendid')

@section('content')
    <div class="container mx-auto">
        <h1 class="text-2xl font-bold mb-8">{{$organisation->name}} - {{$year}} dokumendid</h1>
        <div class="grid grid-cols-4 gap-4">
            @foreach($documentsPerMonth as $perMonth)
                <x-bladewind.card body_class="mb-0 mt-auto p-4 py-0 pb-4" class="flex flex-col">
                    <x-slot:header>
                        <div class="p-4">
                            <a
                                class="underline font-bold mb-auto"
                                href="{{route('archiveMonth', ['orgSlug' => $organisation->slug, 'year' =>  $year, 'month' => $perMonth->month])}}">
                                {{$year}} {{\Illuminate\Support\Str::studly(\Carbon\Carbon::create($year, $perMonth->month)->locale('et')->translatedFormat('F'))}}
                            </a>
                        </div>
                    </x-slot:header>
                    <div class="mt-auto mb-0">
                        <div class="flex mb-2">
                            <div class="ml-0 mr-auto text-gray-500">
                                Dokumentide arv
                            </div>
                            <div>
                                {{$perMonth->count}}
                            </div>
                        </div>
                    </div>
                </x-bladewind.card>
            @endforeach
        </div>
    </div>
@endsection
