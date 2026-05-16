@extends('layout')

@section('title', $organisation->name . ' - dokumendid aastate kaupa')

@section('content')
    <div class="container mx-auto">
        <h1 class="text-2xl font-bold mb-8">Dokumendiregister - {{$organisation->name}} - arhiiv</h1>
        <div class="grid grid-cols-4 gap-4">
            @foreach($documentsPerYear as $peryear)
                <x-bladewind.card body_class="mb-0 mt-auto p-4 py-0 pb-4" class="flex flex-col">
                    <x-slot:header>
                        <div class="p-4">
                            <a
                                class="underline font-bold mb-auto"
                                href="{{route('archiveYear', ['orgSlug' => $organisation->slug, 'year' =>  $peryear->year])}}">{{$peryear->year}}</a>
                        </div>
                    </x-slot:header>
                    <div class="mt-auto mb-0">
                        <div class="flex mb-2">
                            <div class="ml-0 mr-auto text-gray-500">
                                Dokumentide arv
                            </div>
                            <div>
                                {{$peryear->count}}
                            </div>
                        </div>
                    </div>
                </x-bladewind.card>
            @endforeach
        </div>
    </div>
@endsection
