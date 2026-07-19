{{-- Band one of the soft-deletes lesson. The trait hides two costs behind one line of
     code: a where clause every query now carries silently, and a delete that is
     secretly an update. Both are stated as consequences of mechanisms already defined
     elsewhere in the curriculum -- the row-versions lesson's "an UPDATE writes a new
     copy" is assumed rather than re-derived, but restated in plain words here because
     a reader may land on this page first. --}}

<p>
    <code>SoftDeletes</code> does not change what <code>$model->delete()</code> looks like from
    the outside, but it changes what every other query on that model does: Eloquent quietly
    adds <code>where deleted_at is null</code> to the query builder before it ever reaches
    PostgreSQL. You never write that clause. It is there on every <code>find</code>, every
    <code>where</code>, every relationship lookup, on a model that uses the trait &mdash;
    which means whatever index strategy the table uses has to serve that one predicate well,
    because it runs on practically every read the table gets.
</p>

<p>
    An ordinary index on <code>deleted_at</code> is the wrong shape for that job. It sorts
    <b>every</b> row in the table by that column, including the ones that are soft-deleted
    &mdash; the exact rows almost no query ever asks for &mdash; and the values it stores are
    timestamps nobody filters by directly. Picture a hotel's guest register kept as one long
    list sorted by checkout date, with guests still in their rooms mixed in among a century of
    people who already left: to find who is in the building right now you would still have to
    page past everyone who checked out, because the list is sorted by the wrong thing. A
    <b>partial index</b> skips that problem by only existing for the rows that matter:
</p>

<pre>create index concurrently orders_live_idx on orders (id) where deleted_at is null;</pre>

<p>
    That index contains nothing but live rows, so its size tracks how many orders are actually
    open rather than the table's entire history, and it matches Eloquent's own
    <code>where deleted_at is null</code> exactly. The other half of the cost shows up when a
    soft delete happens at scale. <code>Order::where(...)->delete()</code> on a
    <code>SoftDeletes</code> model is not a <code>DELETE</code> &mdash; it is an
    <code>UPDATE</code> that sets <code>deleted_at</code>, and PostgreSQL never edits a row in
    place. Every <code>UPDATE</code> writes an entirely new copy of the row to disk and leaves
    the old copy behind, marked dead rather than erased, so "deleting" a million rows this way
    writes a million new copies. The table gets physically bigger, not smaller, and stays that
    size until something vacuums the dead copies away.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>The predicate has to match, exactly</h3>

    <p>
        A partial index is only ever considered by the planner when the query's own
        <code>WHERE</code> clause provably implies the index's predicate.
        <code>where deleted_at is null</code> in the query matches
        <code>where deleted_at is null</code> in the index definition because they are the same
        condition; a query that filters on something else entirely gets no help from it, because
        the planner cannot prove the index covers the rows being asked for without that literal
        predicate, or one it can derive from it, present in the query.
    </p>

    <h3>withTrashed() cannot use it, and that is correct</h3>

    <p>
        Calling <code>withTrashed()</code> drops Eloquent's automatic scope, so the query no
        longer carries <code>where deleted_at is null</code> at all. The partial index does not
        cover soft-deleted rows, so the planner is right to ignore it and fall back to a full
        table scan or a different index entirely. That is not a bug to work around &mdash; a
        query that deliberately wants the dead rows has no business using an index that
        deliberately excludes them.
    </p>

    <h3>Small enough to stay cached</h3>

    <p>
        Because the partial index never grows past the count of live rows, it stays smaller
        than a plain index on the same column would once soft-deleted rows pile up, and a
        smaller index is more likely to fit entirely in PostgreSQL's shared buffers. An index
        that has to page a chunk of soft-deleted history in and out of cache on every lookup is
        paying a cost the partial index was built specifically to avoid.
    </p>
</div>
