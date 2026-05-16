@extends('layout')

@section('title', 'Dokumendiregistrite arhiivid')

@section('content')
    <div class="container mx-auto px-4">
        <h1 class="text-2xl font-bold mb-8">Dokumendiregistrite arhiivid</h1>
        <div class="grid grid-cols-4 gap-4">
            @foreach($organisations as $organisation)
                <x-bladewind.card body_class="mb-0 mt-auto p-4 py-0 pb-4"
                                  class="md:col-span-1 col-span-4 flex flex-col">
                    <x-slot:header>
                        <div class="p-4">
                            <a
                                class="underline font-bold mb-auto"
                                href="{{route('archiveOrg', $organisation->slug)}}">{{$organisation->name}}</a>
                        </div>
                    </x-slot:header>
                    <div class="mt-auto mb-0">
                        <div class="flex mb-2">
                            <div class="ml-0 mr-auto text-gray-500">
                                Dokumentide arv
                            </div>
                            <div>
                                {{$organisation->documents_count}}
                            </div>
                        </div>
                        <div class="flex mb-2">
                            <div class="ml-0 mr-auto text-gray-500">
                                Millest salastatud
                            </div>
                            <div>
                                {{$organisation->restricted_documents_count}}
                                @if($organisation->documents_count)
                                ({{(round($organisation->restricted_documents_count / $organisation->documents_count * 100, 2))}}%)
                                @endif
                            </div>
                        </div>
                        <div class="flex mb-2">
                            <div class="ml-0 mr-auto text-gray-500">
                                Alates
                            </div>
                            <div>
                                @php($min = $organisation->documents()->min('registration_date'))
                                @if($min)
                                    {{\Carbon\Carbon::parse($min)->format('d.m.Y')}}
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                        <div class="flex">
                            <div class="ml-0 mr-auto text-gray-500">
                                Kuni
                            </div>
                            <div>
                                @php($max = $organisation->documents()->max('registration_date'))
                                @if($max)
                                    {{\Carbon\Carbon::parse($max)->format('d.m.Y')}}
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                    </div>
                </x-bladewind.card>
            @endforeach
        </div>
    </div>
@endsection
