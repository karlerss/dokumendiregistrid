@props(['documents'])

<x-bladewind.table divider="thin">
    <x-slot name="header">
        <th>Nimi</th>
        <th>K.p.</th>
        <th data-tooltip="Sünkroonimise ja registreerimise vahe päevades">Δ</th>
        <th>Viit</th>
        <th>Tüüp</th>
        <th>Org</th>
        <th>Osapooled</th>
        @if(session('is_admin'))
            <th></th>
        @endif
    </x-slot>
    @foreach($documents as $doc)
        <tr>
            <td>
                <a
                    class="underline text-gray-900"
                    href="{{route('document', ['document' => $doc->id, 'slug' => \Illuminate\Support\Str::slug($doc->ai_title ?? $doc->title)])}}">{{$doc->ai_title ?? $doc->title}}</a>
            </td>
            <td>
                {{$doc->registration_date->format('d.m.Y')}}
            </td>
            <td>
                {{round($doc->registration_date->diffInDays($doc->created_at))}}
            </td>
            <td style="white-space: nowrap;">
                {{$doc->reference}}
                @if($doc->restriction !== 'Avalik')
                    🔒
                @endif
            </td>
            <td style="white-space: nowrap;">{{$doc->type}}</td>
            <td>{{$doc->organisation->slug}}</td>
            <td style="max-width: 25vw">{{$doc->to}}</td>
            @if(session('is_admin'))
                <td>
                    <form action="{{route('document.destroy', $doc)}}" method="post" style="display: inline;">
                        @method('DELETE')
                        @csrf
                        <x-bladewind.button
                            size="tiny"
                            can_submit="true"
                            color="red"
                            onclick="return confirm('Are you sure you want to delete this document?');"
                        >
                            <x-bladewind.icon name="trash"/>
                        </x-bladewind.button>
                    </form>
                </td>
            @endif
        </tr>
    @endforeach
</x-bladewind.table>
