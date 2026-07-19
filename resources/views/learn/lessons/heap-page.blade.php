{{-- Band one of the heap-page lesson, and the front door to the internals explorer:
     everything the fillfactor and row-versions lessons already said about "the same
     page" and "a line pointer" only ever made sense as an assertion, because neither
     one showed a reader an actual page. This is where the abstraction becomes
     something they can open and count for themselves. --}}

<p>
    PostgreSQL keeps a table as a stack of fixed-size <b>pages</b>, 8 kB each, and
    never anything else &mdash; a two-row table and a two-billion-row table are both
    just a file made of the same size chunk, one after another. Think of one page as
    a filing envelope: the front of the envelope carries a numbered index card
    listing what is tucked inside and roughly where, and the contents themselves are
    stuffed in from the back, working forward. PostgreSQL calls that index card the
    <b>line pointer array</b>. It grows toward the middle of the envelope as rows are
    added, the actual row data grows toward it from the other end, and whatever gap
    is left between them is the free space a page still has to give.
</p>

<p>
    That index card is not decoration. Every row on the page has a slot in it &mdash;
    a <b>line pointer</b>, numbered from 1 &mdash; and everything else in PostgreSQL
    that needs to find a row, including every index in your database, points at the
    <i>slot</i>, not at the row's bytes directly. The slot is what says where on the
    page the row currently sits. That one layer of indirection is doing more work
    than it looks like it is: because the pointer is a step removed from the data,
    PostgreSQL is free to move the data around underneath it &mdash; shrink it,
    replace it, redirect it to a different slot entirely &mdash; without ever having
    to walk back through every index that mentions it.
</p>

<p>
    That is exactly what an index stores: a page number and a line pointer slot,
    never the row's actual address on disk. So when a row updates and the new
    version fits on the same page &mdash; the HOT update the fillfactor lesson
    described &mdash; PostgreSQL only has to repoint that one slot to the new
    version. Not one index anywhere in the database has to change, because none of
    them ever stored more than "page 4,204, slot 7" to begin with. A row can move
    within its page and every index pointing at it stays correct without being
    touched.
</p>

@if (Route::has('vacuum.internals'))
    <p>
        This is prose about bytes you have not seen yet. <a class="open" href="{{ route('vacuum.internals') }}">Open the page explorer</a>
        and look at one of your own pages: its line pointers, which of them are
        dead, and the HOT chains a plain <code>SELECT</code> never shows you.
    </p>
@else
    <p>
        This package can open a real page for you, but that panel is switched off
        on this connection right now &mdash; see "What to do about it" below for
        exactly why, and what turns it on.
    </p>
@endif

<button type="button" class="why" aria-expanded="false" data-why>what pageinspect actually hands you</button>

<div class="impact" data-impact hidden>
    <h3>heap_page_items, row by row</h3>

    <p>
        <code>pageinspect</code>'s <code>heap_page_items(get_raw_page('table', block))</code>
        returns one row per line pointer on the page: <code>lp</code> is the slot
        number an index would name, <code>lp_off</code> and <code>lp_len</code> are
        where the tuple sits and how long it is, and <code>t_xmin</code> /
        <code>t_xmax</code> are the transaction ids that created and, if it is
        superseded, ended that row version &mdash; the same ids the row-versions
        lesson reads through ordinary columns, seen here at the slot they actually
        occupy.
    </p>

    <h3>What lp_flags means</h3>

    <p>
        Every slot is in exactly one of four states, encoded in <code>lp_flags</code>:
        <code>LP_UNUSED</code> is an empty slot available for reuse, <code>LP_NORMAL</code>
        points at an ordinary tuple, <code>LP_REDIRECT</code> stores no tuple of its
        own and instead forwards to another slot on the same page &mdash; this is
        what is left behind once a HOT chain's original version is pruned, so the
        address every index still holds keeps working &mdash; and <code>LP_DEAD</code>
        is a slot whose tuple is gone but which cannot yet be reused, because an
        index still names it and that reference has not been cleaned up.
    </p>

    <h3>Rows too wide for the page</h3>

    <p>
        A page is 8 kB and PostgreSQL insists on fitting several rows per page, so a
        single value wider than roughly 2 kB is not stored in the page at all: it is
        compressed and sliced into chunks in a separate <b>TOAST</b> table, and the
        row's own page holds only a pointer to that chunk. This is why a table with
        one enormous <code>jsonb</code> or <code>text</code> column can have a
        deceptively small heap and a much larger TOAST relation sitting quietly
        beside it &mdash; this package reports TOAST bytes separately from heap
        bytes for exactly that reason.
    </p>
</div>
