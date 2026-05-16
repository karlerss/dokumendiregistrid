@props([
    'file',
    'parent',
    'depth',
])

<div
    class="cursor-pointer p-2 file-tree-item"
    style="white-space: nowrap; text-overflow: ellipsis; overflow: hidden; --level: {{$depth}};"
    data-file-list-id="{{$file->id}}"
    onclick="showFile({{$file->id}})"
>
    <div style="margin-left: calc(var(--level) * 14px);">{{$file->name}}</div>
</div>
@foreach($file->children as $child)
    <x-file-list-item :file="$child" :parent="$file->id" :depth="$depth + 1"></x-file-list-item>
@endforeach
