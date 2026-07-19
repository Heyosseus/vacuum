{{-- One lesson and whatever builds on it. Recursive, because the curriculum
     nests to whatever depth its edges describe and a @foreach cannot. --}}

<tr>
    <td>
        <a class="open @if ($depth > 0) tree__child @endif" style="padding-left: {{ $depth }}rem"
           href="{{ route('vacuum.lesson', ['lesson' => $node->lesson->slug()]) }}">
            @if ($depth > 0)<span class="tree__stem">&#9492;</span> @endif{{ $node->lesson->title() }}
        </a>
    </td>
    <td class="note">{{ $node->lesson->hook() }}</td>
</tr>

@foreach ($node->children as $child)
    @include('vacuum::learn.node', ['node' => $child, 'depth' => $depth + 1])
@endforeach
