@extends('layout')

@section('title', $organisation->name . ' - ' . \Illuminate\Support\Str::studly(\Carbon\Carbon::create($year, $month)->locale('et')->translatedFormat('F')) . ' ' . $year . ' dokumendid')

@section('content')
    <div class="container mx-auto">
        <h1 class="text-2xl font-bold mb-8">{{$organisation->name}} - {{\Illuminate\Support\Str::studly(\Carbon\Carbon::create($year, $month)->locale('et')->translatedFormat('F'))}} {{$year}} dokumendid</h1>
    </div>
    <x-docs-table :documents="$documents"></x-docs-table>
@endsection
