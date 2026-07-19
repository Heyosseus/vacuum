{{-- Band one of the index cost lesson. The table underneath has already excluded
     constraint-backing indexes, and this prose has to say why before the reader gets
     there and starts writing DROP statements: a primary key nothing has ever looked
     something up by is still doing its job on every single insert. --}}

<p>
    An index is a second copy of part of your data, kept permanently in sorted order. For a
    B-tree &mdash; the kind you get from <code>create index</code> unless you ask for
    something else &mdash; it is a separate file holding the indexed column's values, each one
    paired with the physical address of the row version it came from. Finding
    <code>where email = 'a@b.c'</code> in a sorted structure of a million entries takes about
    twenty comparisons; finding it by reading the table takes a million. That is the whole
    pitch, and it is a very good one.
</p>

<p>
    The bill arrives on the write side, and it is easy to miss because nothing reports it as
    an index cost. Every <code>INSERT</code> writes a new entry into every index on the table.
    Every <code>UPDATE</code> that could not be kept inside its own page does the same. Every
    one of those writes is also written to the write-ahead log, so it is replicated and backed
    up too. Each index is more disk, more of your buffer cache spent on data nobody queried,
    another structure <code>VACUUM</code> has to walk end to end, and more work for the query
    planner on every statement against the table. Six indexes on a table means an insert into
    it is seven writes.
</p>

<p>
    PostgreSQL counts how many times the planner has actually used each index, in
    <code>idx_scan</code>. Zero means no query has read it since the statistics were last
    reset &mdash; pure cost, paid on every write, returning nothing. But <b>do not read that
    number without checking what the index is for</b>. A primary key or a unique index is how
    PostgreSQL enforces uniqueness: every insert probes it to find out whether the value is
    already there, and that probe is not counted as a scan. An index like that can show zero
    scans forever and still be load-bearing, which is why the list below leaves constraint
    indexes out entirely. What remains is the honest candidates, and they come off like this:
</p>

<pre>drop index concurrently if exists orders_label_idx;</pre>

<p>
    <code>CONCURRENTLY</code> is what keeps the drop from taking a lock that blocks queries on
    the table. Two warnings before you run it anywhere real: these counters are per-server, so
    an index used only by queries on a read replica shows zero scans here, and they are reset
    by <code>pg_stat_reset()</code> and by a restore &mdash; check that the numbers have had a
    full business cycle to accumulate, including whatever runs monthly.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>What is in the index entry</h3>

    <p>
        A B-tree leaf entry holds the indexed values and a <code>ctid</code>: the block number
        and line pointer of the heap tuple. Because MVCC keeps several versions of a row, the
        index contains an entry for each version that ever existed and has not yet been vacuumed
        away &mdash; the index does not know which of them you can see. That is why an index scan
        must visit the heap to check visibility, and why index bloat tracks table churn rather
        than row count.
    </p>

    <h3>Index-only scans are not free of the heap</h3>

    <p>
        When every column a query needs is in the index, PostgreSQL can answer without reading
        the table &mdash; but only for pages the visibility map has marked all-visible, because
        otherwise it cannot know whether an entry refers to a version you are allowed to see. An
        index-only scan on a table that is written to constantly and vacuumed rarely degrades
        quietly into an ordinary index scan, and <code>EXPLAIN (ANALYZE)</code> shows it as
        "Heap Fetches".
    </p>

    <h3>Before you drop it</h3>

    <p>
        An index that is used but bloated wants <code>REINDEX INDEX CONCURRENTLY</code>, not a
        drop. An index used by one narrow query may want to become a partial index with a
        <code>WHERE</code> clause, which can be a fraction of the size and a fraction of the
        write cost. And an index that duplicates the leading columns of another is genuinely
        redundant: a B-tree on <code>(a, b)</code> serves lookups on <code>a</code> alone, so a
        separate index on <code>(a)</code> earns nothing and is charged for on every write.
    </p>
</div>
