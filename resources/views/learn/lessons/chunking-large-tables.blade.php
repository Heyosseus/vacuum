{{-- Band one of the chunking-large-tables lesson. The reader already writes
     Model::chunk() without thinking about it, so the page starts from what that
     call compiles to rather than from PostgreSQL internals -- the SQL is the
     bridge between the Eloquent they know and the page-and-index machinery the
     rest of the curriculum explains. The correctness trap gets its own paragraph
     because a reader skimming for "is this slow" will miss that it is also
     sometimes wrong. --}}

<p>
    <code>Model::chunk(1000, function ($rows) { ... })</code> compiles to exactly
    what it looks like it should: <code>select * from orders order by id limit 1000
    offset 0</code>, then the same query again with <code>offset 1000</code>, then
    <code>offset 2000</code>, and so on until a page comes back empty. Each call
    only asks for 1,000 rows, so it feels like a fixed cost repeated. It is not.
</p>

<p>
    PostgreSQL has no way to jump to row 500,000 of a sorted result &mdash; a btree
    index tells it where a <i>value</i> lives, not where a <i>position</i> in the
    output lives. So <code>OFFSET 500000</code> does not skip 500,000 rows, it
    <b>produces and discards</b> every one of them before the first row you asked
    for can be returned. It is a bookmark that only works by recounting the pages
    from the front of the book every single time you use it, rather than one that
    remembers where you already were. Chunk one pays almost nothing; chunk five
    hundred pays for the four hundred ninety-nine chunks that came before it, on
    top of its own 1,000 rows. A job that felt instant in a staging database with
    ten thousand rows degrades to minutes once the table has ten million.
</p>

<p>
    <code>chunkById()</code> and its cursor cousin <code>lazyById()</code> ask a
    different question: not "give me the rows at this offset" but "give me the
    rows with <code>id</code> greater than the last one I saw." That is
    <code>where id &gt; ? order by id limit ?</code>, and an index answers it the
    same way whether the last id was 1 or 10,000,000 &mdash; a seek, not a count.
    There is also a correctness reason to prefer it, not just a speed one: if a job
    updates the very column <code>chunk()</code> is ordering by while it walks the
    table, the offset window shifts underneath it and rows get silently skipped.
    <code>chunkById()</code> does not have this problem, because the next page is
    always defined by the last id actually seen, not by a count of everything
    already produced.
</p>

<pre>Order::chunkById(1000, fn ($orders) => ..., column: 'id');</pre>

<button type="button" class="why" aria-expanded="false" data-why>why order matters, and the memory-safe forms</button>

<div class="impact" data-impact hidden>
    <h3>OFFSET is only meaningful with ORDER BY</h3>

    <p>
        Without an explicit <code>ORDER BY</code>, PostgreSQL is free to return rows
        in whatever order the current scan happens to produce them in, which can
        change between two executions of the identical query &mdash; a different
        query plan, a vacuum that moved rows, an index scan instead of a sequential
        one. <code>OFFSET</code> and <code>LIMIT</code> slice into that order, so an
        unordered chunked query is not merely slow, it has no guarantee of visiting
        every row exactly once at all. <code>chunk()</code> and <code>chunkById()</code>
        both add the ordering for you, which is precisely why hand-rolling the same
        loop without one is a common way to reintroduce this bug.
    </p>

    <h3>lazyById() and cursor() are not the same memory trade</h3>

    <p>
        <code>chunkById()</code> still loads each page into an array in memory before
        handing it to the callback. <code>lazyById()</code> wraps the same keyset
        query in a <code>LazyCollection</code>, so only one page is ever held at a
        time regardless of how the caller iterates. <code>cursor()</code> looks
        similar but is a different mechanism entirely: it holds open a single
        database cursor for the whole run inside one long-lived transaction. That
        transaction is exactly the kind of thing the rest of this curriculum warns
        about &mdash; autovacuum cannot remove a dead row that a transaction still
        open might need to see, so a long <code>cursor()</code> run over a busy table
        can itself be the reason dead tuples pile up while it works.
    </p>

    <h3>The complexity difference, stated plainly</h3>

    <p>
        A keyset seek on an indexed column costs <code>O(log n)</code> to find the
        starting point of each page, the same as any index lookup, independent of
        how many chunks came before it. <code>OFFSET n</code> costs <code>O(n)</code>
        &mdash; work proportional to how deep into the table the offset reaches. Walk
        the whole table in chunks of 1,000 and the total work done by
        <code>OFFSET</code> alone, summed across every chunk, grows with the square
        of the table's size; the total work done by a keyset seek grows linearly
        with it, which is the entire difference between a job that finishes in
        seconds and one that is still running an hour later.
    </p>
</div>
