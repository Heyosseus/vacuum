{{-- Band one of the dead tuples lesson. It follows on from row versions and repeats
     the one definition it depends on rather than assume the reader arrived in order.
     The number the lesson is really for is "vacuums at": autovacuum's threshold is a
     share of the table, which is the fact that explains why the biggest table in a
     database is always the one carrying the most garbage. --}}

<p>
    An <code>UPDATE</code> or a <code>DELETE</code> in PostgreSQL never removes anything
    immediately. It writes a new copy, or no copy at all, and marks the old one as ended.
    That old copy &mdash; one physical version of a row, which PostgreSQL calls a
    <b>tuple</b> &mdash; has to stick around while any transaction that started earlier
    might still need to see it. Once no running transaction could possibly want it, it is a
    <b>dead tuple</b>: bytes on disk that no query will ever return, still sitting in the
    middle of your table.
</p>

<p>
    They are not free. A sequential scan reads every page of the table and steps over the
    dead tuples one at a time, so a table that is half garbage does roughly twice the I/O to
    answer the same question. They occupy your disk, and worse, they occupy your buffer cache
    &mdash; RAM you were hoping to spend on rows somebody actually asked for.
    <code>VACUUM</code> is the job that walks the table and marks the space those dead tuples
    occupy as reusable, so the next insert or the next new row version can land there instead
    of extending the file. Note what it does <b>not</b> do: it does not hand the space back to
    the operating system. The table stays the same size on disk and simply stops growing. The
    only thing that shrinks a table is <code>VACUUM FULL</code>, which rewrites it from
    scratch and holds a lock that blocks every read and every write for the duration.
</p>

<p>
    You do not normally run <code>VACUUM</code> yourself. A background process called
    <b>autovacuum</b> watches the tables and runs it when a table has accumulated enough dead
    tuples &mdash; and "enough" is the part that surprises people, because it is a
    <b>share</b> of the table, not a fixed number. The default is
    <code>50 + 0.2 &times; live rows</code>. A table with a thousand rows waits for 250 dead
    ones. A table with ten million rows waits for two million and fifty. That is entirely
    deliberate &mdash; vacuuming a huge table is expensive and you do not want it happening
    over a rounding error &mdash; but it means your biggest, busiest table is allowed to carry
    an enormous amount of garbage before anything happens. The fix is per-table, and it is one
    statement:
</p>

<pre>alter table events set (autovacuum_vacuum_scale_factor = 0.02);</pre>

<p>
    The <b>vacuums at</b> column below is that arithmetic done for each of your own tables: the
    dead row count at which autovacuum will next take an interest. Compare it to the dead rows
    the table is holding now, and you can see exactly how long the queue is.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>The numbers are estimates</h3>

    <p>
        <code>n_dead_tup</code> in <code>pg_stat_user_tables</code> comes from the cumulative
        statistics system, which backends report into asynchronously and which is not
        crash-safe. It drifts, it is reset by <code>pg_stat_reset()</code>, and on a replica it
        counts that replica's own activity. For an exact answer you need
        <code>pgstattuple</code>, which reads every page and costs accordingly.
    </p>

    <h3>What a vacuum actually does</h3>

    <p>
        A vacuum makes a first pass over the heap collecting the tuple ids of dead tuples into
        an array sized by <code>maintenance_work_mem</code>, then a second pass over every index
        on the table removing entries that point at them, then a third over the heap turning
        those line pointers into free space. If the array fills, it flushes and starts again
        &mdash; and every restart means another full scan of <b>every</b> index. Vacuum's
        <code>index_vacuum_count</code> going above one is the signal that
        <code>maintenance_work_mem</code> is too small for that table; PostgreSQL 17 replaced the
        flat array with a radix tree that holds far more in the same memory, which is why this
        bites much less there.
    </p>

    <h3>Pruning, and the other deadline</h3>

    <p>
        Ordinary <code>SELECT</code>s already do some of this work opportunistically. When a
        backend reads a page and notices dead tuples that a HOT chain has superseded, it prunes
        them on the spot without waiting for a vacuum. That is page-local and does not touch
        indexes, so it reclaims space within a page but never removes an index entry.
    </p>

    <p>
        There is also a vacuum that ignores every threshold on this page. When a table's oldest
        unfrozen transaction id gets within <code>autovacuum_freeze_max_age</code> of wrapping
        around, an anti-wraparound autovacuum starts whether you wanted it or not, and it does
        not yield to a conflicting lock the way a normal one does. A table nobody has vacuumed
        in months will eventually be vacuumed at the least convenient possible moment.
    </p>
</div>
