{{-- Band one of the fillfactor lesson, and the one that pays for itself: a reader who
     understands the two HOT conditions can halve the write cost of a hot table with a
     single ALTER. The page is defined here rather than assumed, because "the new row
     version has to fit on the same page" is meaningless until you know a page is a
     fixed 8 kB and a table is nothing but a pile of them. --}}

<p>
    PostgreSQL does not store a table as a stream of rows. It stores it as a sequence of
    fixed-size chunks called <b>pages</b> &mdash; 8 kB each, always, however wide your rows
    are. A page holds as many row versions as fit, plus a small directory at the front
    pointing at each one. When PostgreSQL needs a row it reads the whole page containing it,
    because 8 kB is the smallest unit it ever reads or writes. A table of two million rows is
    just a file of a great many 8 kB pages.
</p>

<p>
    Now recall that an <code>UPDATE</code> writes a new copy of the row rather than editing
    the old one. Where that copy lands is worth real money. If it lands on a different page,
    every index on the table has to be given a new entry pointing at the new location &mdash;
    a table with six indexes turns one logical update into seven physical writes, all of which
    have to be written to the write-ahead log as well. But if the new copy fits on the
    <b>same page</b> as the old one, <b>and</b> the update did not change any column that is
    indexed, PostgreSQL takes a shortcut: it links the new version to the old one inside the
    page and updates no index at all. Zero index writes. That shortcut is called a <b>HOT
    update</b>, for Heap-Only Tuple &mdash; the new version exists only in the heap, and no
    index knows or needs to know it is there. The <b>HOT share</b> column below is the
    percentage of a table's updates that got that deal.
</p>

<p>
    Which brings us to <b>fillfactor</b>: the percentage of each page PostgreSQL is willing to
    fill when inserting new rows. The default is 100, meaning pack every page completely full,
    which is perfect for a table you only ever insert into and read. On a table you update, it
    is the worst possible setting &mdash; there is no room left on the page for the new copy,
    so the update leaves the page and pays every index. Lowering fillfactor deliberately
    wastes some space on each page so that later updates have somewhere to land. Think of a
    printed form where every line is filled edge to edge versus one with a margin: the margin
    is wasted paper right up until you need to write a correction next to the original line
    rather than on a new sheet with a new entry in the index at the back.
</p>

<pre>alter table sessions set (fillfactor = 85);</pre>

<p>
    That setting applies to pages written from then on; the pages already packed at 100% stay
    packed until something rewrites them. <code>VACUUM FULL</code> or a
    <code>pg_repack</code> run will do it, at the cost of a lock. And it is a trade, not a
    free win: 85 means roughly 15% more pages for the same rows, so more disk and more cache
    for every read. It is worth it on a narrow, heavily updated table &mdash; sessions, job
    queues, counters &mdash; and worth nothing at all on an append-only log. The other half of
    the fix is free: an index on a column that changes constantly disqualifies every update on
    the table from HOT, so dropping an index nothing needs can raise the HOT share on its own.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>How the chain is actually stored</h3>

    <p>
        A page's directory is an array of <b>line pointers</b> growing downward from the header
        while tuples are written upward from the end; the gap between <code>pd_lower</code> and
        <code>pd_upper</code> is the free space fillfactor is arguing about. An index entry points
        at a line pointer, not at a tuple, and that indirection is what makes HOT possible.
    </p>

    <p>
        On a HOT update the old tuple's <code>t_ctid</code> is pointed at the new one and the new
        one is flagged <code>HEAP_ONLY_TUPLE</code>, so an index scan arriving at the old line
        pointer follows the chain forward to the current version. When the old version is later
        pruned, its line pointer is not freed &mdash; it is turned into an
        <code>LP_REDIRECT</code> that forwards to the survivor, so the address the index holds
        stays valid. This is why the space a HOT chain reclaims can be recovered by an ordinary
        <code>SELECT</code> touching the page, with no vacuum and no index work whatsoever.
    </p>

    <h3>Which columns count as indexed</h3>

    <p>
        The test is whether the update changed any column that appears in <b>any</b> index on
        the table, evaluated as a bitmap of attribute numbers &mdash; not whether the index would
        have found a different value. Setting a column to the value it already held does not
        break HOT, because the comparison is on the stored bytes. Columns in an index's
        <code>INCLUDE</code> list do count. From PostgreSQL 16 a summarising index such as BRIN
        no longer disqualifies an update, since it does not point at individual tuples.
    </p>

    <h3>Reading the counters honestly</h3>

    <p>
        <code>n_tup_hot_upd</code> counts successful chains; <code>n_tup_upd</code> counts all
        updates. PostgreSQL 16 added <code>n_tup_newpage_upd</code>, which isolates the updates
        that failed specifically because the page was full &mdash; those are the ones fillfactor
        can fix. A low HOT share caused by an indexed column changing instead will not move an
        inch however much room you leave, and the two are indistinguishable in the ratio alone.
    </p>
</div>
