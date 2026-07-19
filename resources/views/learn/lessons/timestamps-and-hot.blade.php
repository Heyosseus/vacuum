{{-- Band one of the timestamps-and-hot lesson. Builds on the fillfactor lesson's
     definition of a page and a HOT update, so this restates the HOT condition in one
     sentence rather than assuming the reader arrived from there -- but leans on it for
     everything else, including the everyday analogy for what a page is. --}}

<p>
    <code>$table->timestamps()</code> is two lines in a migration and two columns
    forever after: <code>created_at</code>, written once, and <code>updated_at</code>,
    rewritten on every single <code>$model->save()</code> whether or not anything you
    actually care about changed. Eloquent does this quietly and correctly &mdash; it is
    exactly what "track when a row last changed" means. Nobody looks twice at it, because
    nothing about it looks expensive from the model side.
</p>

<p>
    Recall the <a href="{{ route('vacuum.lesson', ['lesson' => 'fillfactor']) }}">fillfactor
    lesson</a>'s HOT update: PostgreSQL skips writing to any index at all when the new row
    version fits on the same page as the old one <b>and</b> the update touched no column
    that any index covers. Both conditions, every time, or the shortcut is off. Fillfactor
    is how you buy room for the first condition. This lesson is about the second one, and
    fillfactor cannot touch it at any setting.
</p>

<p>
    Put an index on <code>updated_at</code> &mdash; to make <code>orderBy('updated_at')</code>
    fast, which is a completely reasonable thing to want &mdash; and the second condition
    breaks for good. Every <code>save()</code> writes <code>updated_at</code>, so every
    update now touches an indexed column, so no update on that table can ever be HOT again,
    for as long as the index exists. It is like laminating one page of a filing folder so a
    clerk can flip straight to today's date: from then on, every single amendment to that
    folder needs the laminated page reprinted, even the ones that have nothing to do with
    dates. One small addition, and the shortcut that used to work for everyone in the folder
    stops working for anyone.
</p>

<pre>create index orders_updated_at_idx on orders (updated_at);</pre>

<p>
    The index does exactly what it was built for &mdash; that <code>ORDER BY</code> gets
    fast &mdash; and it charges every write on the table forever to do it. That trade is
    sometimes worth making. It is never worth making by accident, which is what happens
    when the index goes in in one migration and nobody connects it to write performance in
    another.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>The test is on bytes, not on meaning</h3>

    <p>
        Whether an update qualifies for HOT is decided by comparing the old and new tuple as
        a bitmap of attribute numbers: any attribute number that appears in any index on the
        table disqualifies the update if its stored bytes changed. <code>updated_at</code> is
        set to a new timestamp on essentially every save, so it almost always changes &mdash;
        but even the rare case where you write back the exact value it already held would
        still count as unchanged, because the comparison is on the bytes, not on intent.
        Columns that only appear in an index's <code>INCLUDE</code> list count too, even
        though they are never used to look a row up.
    </p>

    <h3>One exception, since PostgreSQL 16</h3>

    <p>
        A BRIN index summarises a range of pages rather than pointing at individual tuples,
        so from PostgreSQL 16 onward it no longer disqualifies an update from HOT the way a
        B-tree does. A BRIN index on <code>updated_at</code> gets you a coarse range scan
        without this cost; a B-tree index on it does not.
    </p>

    <h3>The cost multiplies across tables, not just rows</h3>

    <p>
        Eloquent's <code>touch()</code>, and a <code>belongsTo</code> relationship declared
        with <code>$touches</code>, write <code>updated_at</code> on the related model too
        whenever the child is saved. If that parent table also has an index on
        <code>updated_at</code>, every save on the child now costs a non-HOT update on the
        parent as well &mdash; the trap is not confined to the table you indexed, it follows
        every write that propagates into it.
    </p>
</div>
