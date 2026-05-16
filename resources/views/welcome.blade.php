@extends('layout')

@section('title', 'Dokumendiregistrite koondvaade - otsing, lk '. request('page', 1))

@section('head')
    <link rel="canonical" href="{{url('/')}}" />
@endsection

@section('description', 'dokumendiregistrid.karlerss.com koondab kokku riigiasutuste dokumendiregistrid, võimaldab koondotsingut ja dokumentide sisusid vaadata.')

@section('content')
    <div class="px-4">
        <form action="" id="search">
            <div class="grid grid-cols-8 gap-2">
                <div class="md:col-span-2 col-span-8">
                    <x-bladewind.input size="small"
                                       name="query"
                                       can_submit="true"
                                       :value="request('query')"
                                       placeholder="Otsisõna"/>
                </div>
                <div class="md:col-span-2 col-span-8">
                    <x-bladewind.select
                        name="org_ids"
                        size="small"
                        :selected_value="request('org_ids')"
                        multiple="true"
                        label_key="name"
                        placeholder="Organisatsioon"
                        value_key="id"
                        id="org_select"
                        searchable="true"
                        :data="\App\Models\Organisation::query()->orderBy('name')->get()"/>
                </div>
                <div class="md:col-span-3 col-span-8">
                    <div class="grid gap-2 grid-cols-3">
                        <x-bladewind.datepicker
                            name="date_start"
                            placeholder="Alates"
                            size="small"
                            :default_date="request('date_start')"
                        ></x-bladewind.datepicker>
                        <x-bladewind.datepicker
                            name="date_end"
                            placeholder="Kuni"
                            size="small"
                            :default_date="request('date_end')"
                        ></x-bladewind.datepicker>
                        <x-bladewind.input size="small"
                                           name="min_delta"
                                           can_submit="true"
                                           :value="request('min_delta')"
                                           placeholder="min. Δ"/>
                    </div>
                </div>
                <div class="md:col-span-1 col-span-8">
                    @php($sortOptions = [
                        ['label' => 'Registreerimise k.p.', 'value' => 'registration_date'],
                        ['label' => 'Sünkroonimise k.p.', 'value' => 'created_at'],
                    ])
                    <x-bladewind.select
                        name="sort_by"
                        :selected_value="request('sort_by')"
                        label_key="label"
                        value_key="value"
                        placeholder="Järjestus"
                        size="small"
                        id="sort_by"
                        :data="$sortOptions"/>
                </div>
                <div class="py-1">
                    <div>
                        <span class="text-gray-400 text-sm">{{$documents->total()}} {{$documents->total() === 1 ? 'dokument' : 'dokumenti'}}</span>
                    </div>
                </div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div class="py-1">
                    <x-bladewind.checkbox label="AK dok-dega" name="with_restricted" value="1"
                                          :checked="request('with_restricted') == 1"
                    />
                </div>
                <div class="md:col-span-1 col-span-8 md:text-right text-center">
                    <x-bladewind.button
                        onclick="document.querySelector('#search').submit();"
                        class="w-full"
                        size="small">Otsi
                    </x-bladewind.button>
                </div>
            </div>
        </form>

    </div>
    @if(isset($error))
        <div class="p-4">
            <x-bladewind.alert :type="'error'" :message="$error">
                {{$error}} <a href="https://www.sqlite.org/fts5.html#full_text_query_syntax" class="underline"
                              target="_blank">Loe lähemalt</a>.
            </x-bladewind.alert>
        </div>
    @endif
    <div class="p-4">
        <x-docs-table :documents="$documents"></x-docs-table>
        <div class="container mx-auto mt-4">
            {!! $documents->withQueryString()->onEachSide(5)->links() !!}
        </div>
    </div>
@stop

@section('script')
    <script>
        document.querySelector('input[name="query"]').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                document.querySelector('#search').submit();
            }
        });
        document.querySelector('input[name="min_delta"]').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                document.querySelector('#search').submit();
            }
        });

        // when org select is clicked, focus the shown input
        document.querySelector('.bw-select-org_ids .clickable').addEventListener('click', function () {
            setTimeout(() => {
                document.querySelector('.bw-select-org_ids input.bw_search').focus();
            }, 1);
        });
    </script>
@endsection
