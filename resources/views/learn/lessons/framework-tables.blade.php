{{-- Band one of the framework-tables lesson. The point is not a new PostgreSQL
     concept -- it is pointing an already-learned concept (row versions, dead
     tuples) at tables the reader has never once thought of as tables, because
     nobody wrote the migration by hand. --}}

<p>
    When you set <code>SESSION_DRIVER=database</code>, <code>QUEUE_CONNECTION=database</code> or
    <code>CACHE_STORE=database</code> in a Laravel application, you are not choosing a special
    storage engine. You are choosing an ordinary PostgreSQL table &mdash; <code>sessions</code>,
    <code>jobs</code>, <code>cache</code> &mdash; created for you by
    <code>php artisan session:table</code> or <code>queue:table</code> the one time somebody ran
    it, and never looked at again. It has all the costs of any other table: pages, indexes,
    dead rows, an autovacuum threshold. It just doesn't look like one, because nobody hand-wrote
    its migration the way they hand-wrote the migration for <code>orders</code> or
    <code>users</code>, so it never made it into anyone's mental picture of "my schema."
</p>

<p>
    And it is a busier table than almost anything you did write. The database session driver
    writes a row on <b>every single request</b> a logged-in user makes, to record where they
    are and when they were last seen. Recall that PostgreSQL never edits a row in place: an
    <code>UPDATE</code> writes an entirely new copy of the row and marks the old copy
    <b>dead</b> &mdash; still taking up space on the page, still counted, until a vacuum comes
    along and reclaims it. A session table under real traffic is rewriting itself constantly,
    which means it is manufacturing dead rows constantly, on a schedule set by how many people
    are using your application right now rather than by anything you configured.
</p>

<p>
    The queue table tells the same story from the other direction. Every job is an
    <code>INSERT</code> followed, on completion, by a <code>DELETE</code> &mdash; and a delete
    does not remove a row's space either, it just marks it dead the same way an update's old
    copy is dead. A queue processing ten thousand jobs an hour is not a table with ten thousand
    rows in it; most of the time it is a nearly empty table sitting on top of a large pile of
    rows PostgreSQL has not yet had the chance to clean up. Think of a restaurant order pad: the
    ticket gets torn off and thrown away the moment the food goes out, but somebody still has to
    empty the bin, and until they do the bin is where most of tonight's paper actually is.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>Why a queue table is the worst case, not just a normal case</h3>

    <p>
        An ordinary table accumulates dead rows as a side effect of being updated; a queue
        table's entire job is insert-then-delete on a short timer, which means the ratio of
        dead rows to live rows can run far higher than on a table nobody bothers to vacuum
        aggressively. Between two autovacuum runs, a busy queue table can spend most of its
        existence holding more dead tuples than live ones, because "live" for a queued job
        might be measured in seconds.
    </p>

    <h3>The visibility map goes cold on a table like this</h3>

    <p>
        PostgreSQL keeps a <b>visibility map</b>, one bit per page, marking pages where every
        row is known visible to every transaction -- the pages an index-only scan can trust
        without touching the heap at all. A page that is being inserted into and deleted from
        constantly never stays all-visible for long enough to earn that bit, so a hot queue or
        session table gets none of the benefit of index-only scans that a quieter table of the
        same shape would get almost for free.
    </p>

    <h3>How the queue driver actually reads the table</h3>

    <p>
        Laravel's database queue driver pulls the next job with a <code>SELECT ... FOR UPDATE
        SKIP LOCKED</code>: it locks the row it is about to hand to a worker, and tells
        PostgreSQL to skip over any row a different worker already has locked rather than wait
        for it. That is what lets several queue workers pull from the same table concurrently
        without stepping on each other or blocking -- but it does nothing to change how many
        dead rows the delete-heavy cycle leaves behind for autovacuum to clean up afterward.
    </p>
</div>
