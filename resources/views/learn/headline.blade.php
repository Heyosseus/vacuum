{{-- A lesson's headline is a sentence with the reader's own table names inside it,
     and a table name buried in prose is invisible. The lessons mark theirs with
     backticks, the way anybody writing about code already does, and this is the one
     place that turns them into <code>.

     The order is the whole point: escape first, so a table somebody managed to name
     with an angle bracket is neutralised, and only then promote the backtick pairs
     that survived. Doing it the other way round would let a headline write markup.
     No headline is ever rendered any other way. --}}
@php
    $marked = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', e($headline));
@endphp
{!! $marked !!}
