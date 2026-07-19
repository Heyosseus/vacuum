{{-- Band one of the row versions lesson. Everything above the fold is written for
     somebody who has shipped Laravel for years and has never once had a reason to
     care what an UPDATE does to a file on disk. Every term it uses -- tuple, heap,
     transaction id, ctid -- is defined the first time it appears, because the table
     underneath this prose has columns named after three of them. The fold is where
     the tuple header and the visibility map are allowed to exist. --}}

<p>
    An <code>UPDATE</code> does not change a row. That is the one sentence to take away,
    and almost nothing about PostgreSQL makes sense until you believe it. When you run
    <code>update orders set status = 'paid' where id = 1</code>, PostgreSQL leaves the
    existing copy of that row exactly where it is, writes a complete second copy with the
    new value somewhere else in the table, and marks the old copy as having ended. For a
    moment, and often for much longer than a moment, order 1 exists on disk twice.
</p>

<p>
    Think of an accountant's ledger, the kind you are not allowed to erase. Correcting an
    entry means ruling a line through the old one, writing the new one underneath, and
    noting who made each change and when. The crossed-out entry stays on the paper. Anybody
    who started reading the ledger before the correction can keep reading their version and
    still see something consistent. PostgreSQL calls one of those physical copies a
    <b>tuple</b>, and the file the tuples live in is the <b>heap</b> &mdash; the table's real
    data file on disk. A row is what you asked for; a tuple is one copy of it that actually
    exists somewhere.
</p>

<p>
    Every tuple carries three hidden columns you can select by name. <code>xmin</code> is the
    id of the transaction that created it and <code>xmax</code> is the id of the transaction
    that ended it, or <code>0</code> while nothing has &mdash; and a <b>transaction id</b> is
    just a counter PostgreSQL bumps by one for each transaction that writes, so a bigger
    number means later. <code>ctid</code> is the tuple's physical address, printed as
    <code>(block, offset)</code>: which 8 kB chunk of the file it sits in, and which slot in
    that chunk. When your query starts it takes a <b>snapshot</b> &mdash; a note of which
    transactions had already finished at that instant &mdash; and shows you a tuple only if
    its <code>xmin</code> finished before you started and its <code>xmax</code> has not
    finished at all. That single test is the whole of MVCC, and it is why a long report never
    blocks a write, why a write never blocks the report, and why a table that only ever gets
    updated still grows.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>The tuple header</h3>

    <p>
        Every heap tuple is prefixed by a 23-byte <code>HeapTupleHeaderData</code>, rounded up to
        24. It holds <code>t_xmin</code> and <code>t_xmax</code>, a command id distinguishing
        statements within one transaction, and <code>t_ctid</code>, which normally points at the
        tuple itself but points forward to the newer version once one exists &mdash; that forward
        pointer is how an update chain is walked.
    </p>

    <p>
        Two bitmask fields, <code>t_infomask</code> and <code>t_infomask2</code>, carry the rest.
        The interesting bits are the <b>hint bits</b>: <code>HEAP_XMIN_COMMITTED</code> and
        <code>HEAP_XMAX_COMMITTED</code>. Deciding visibility strictly by the rules above would
        mean consulting the commit log, <code>pg_xact</code>, for every tuple on every scan. The
        first reader to look one up writes the answer back into the tuple as a hint bit, so
        everybody after it reads the answer for free. This is why the first
        <code>SELECT</code> after a bulk load can dirty pages and produce write traffic from a
        statement that only reads.
    </p>

    <h3>The visibility map</h3>

    <p>
        Alongside the heap sits a <code>_vm</code> fork with two bits per page. The all-visible
        bit says every tuple on that page is visible to every transaction, which lets a scan skip
        the visibility test entirely and lets an index-only scan return values without touching
        the heap at all. The all-frozen bit says the page needs no further attention from a
        wraparound vacuum. Both are cleared the moment anything on the page is written.
    </p>

    <h3>Where xmin eventually runs out</h3>

    <p>
        Transaction ids are 32 bits and they wrap. PostgreSQL compares them modulo 2<sup>32</sup>,
        so "older than" is only meaningful within a window of about two billion; beyond it, an
        ancient tuple would start looking like a future one and vanish. <code>VACUUM</code>
        prevents that by <b>freezing</b> old tuples &mdash; marking them as unconditionally visible
        rather than as belonging to any particular transaction id. A database that never gets
        vacuumed will eventually be forced into single-user mode to be rescued, and that is the
        deadline the maintenance lessons are ultimately about.
    </p>
</div>
