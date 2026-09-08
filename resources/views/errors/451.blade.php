@extends('layout')

@section('title', 'Dokument ei ole kättesaadav')

@section('content')
    <div class="px-4">
        <div class="container mx-auto max-w-2xl py-16 text-center">
            <h1 class="text-3xl font-bold mb-4">Dokument ei ole kättesaadav</h1>
            <p class="text-gray-700 mb-2">
                Selle dokumendi näitamine on peatatud, sest allikaregister on sellele seadnud juurdepääsupiirangu
                või dokument on allikast eemaldatud.
            </p>
            <p class="text-gray-500 text-sm mb-8">HTTP 451 — Unavailable For Legal Reasons</p>
            <a href="/" class="underline">Tagasi otsingusse</a>
        </div>
    </div>
@endsection
