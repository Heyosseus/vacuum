{{-- Band one of the transactions-and-locks lesson, and the most consequential idea in
     the whole section: DB::transaction() with anything slow in the closure does not
     merely slow down its own request, it stops vacuum cleaning tables it never touched.
     The everyday analogy carries the whole point, so it is stated once and plainly
     rather than buried under the mechanism. --}}

<p>
    <code>DB::transaction(function () { ... })</code> does exactly two things: it opens one
    transaction before the closure runs, and it holds that transaction open until the
    closure returns &mdash; committing it if the closure finishes, rolling it back if it
    throws. Everything inside the closure runs inside that same open transaction, whether
    it touches the database or not. An <code>Http::post()</code> call to a slow API, a
    queue job dispatched synchronously, a file upload, a <code>sleep()</code>, or a
    <code>foreach</code> loop over ten thousand records all do the same thing to PostgreSQL:
    they keep the transaction open for as long as they take, and PostgreSQL has no way to
    tell "still doing real work" apart from "forgot to finish".
</p>

<p>
    PostgreSQL never edits a row in place. An <code>UPDATE</code> or <code>DELETE</code>
    leaves the old row version on the page and writes a new one (or none), and it is
    <b>vacuum</b>'s job to come back later and reclaim the old ones. But vacuum can only
    reclaim a row version if it is certain that no transaction anywhere on the server could
    still need to read it &mdash; and "anywhere on the server" is the whole database, not
    the table the row lives in. A transaction that opened five minutes ago and has not
    committed yet might, in principle, run a query against any table at any moment before
    it does, so every row version newer than that transaction started has to stay exactly
    where it is, untouched, in every table, until that transaction ends.
</p>

<p>
    Which is the trap: the transaction does not have to touch a table for its mere presence
    to stop that table being cleaned. One connection sitting idle inside an open transaction
    is like one person holding a fire door open at the far end of a building &mdash; the
    cleaners on every other floor still cannot do their round, because the building's rule
    is "nobody in, nobody working" while any door anywhere is propped open, not "work around
    whichever door happens to be blocked." A single forgotten <code>Http::post()</code>
    inside a <code>DB::transaction()</code> closure can bloat tables that transaction never
    even queried, and left running long enough against a busy database it is one of the
    ways a server reaches transaction-ID wraparound.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>The xmin horizon</h3>

    <p>
        Every transaction gets a monotonically increasing transaction ID, and every row
        version is stamped with the ID that created it (<code>xmin</code>) and, once
        superseded, the ID that replaced it (<code>xmax</code>). Vacuum computes a single
        number for the whole server &mdash; the oldest transaction ID that any current
        snapshot might still need to see &mdash; and refuses to remove any row version whose
        <code>xmax</code> is newer than that number, on any table, because doing so could
        make a row disappear out from under a transaction that has not finished reading yet.
        One transaction open since before a row died is enough to hold that number still, no
        matter which table the transaction is actually touching.
    </p>

    <h3>Idle versus active</h3>

    <p>
        <code>pg_stat_activity.state</code> tells the two apart: <code>active</code> means
        PostgreSQL is currently executing a statement for that backend, while
        <code>idle in transaction</code> means the transaction is still open but nothing is
        running &mdash; the backend is waiting on the application, not the other way round.
        Both hold the horizon identically; the difference only matters for where to look.
        An active session is doing something you can watch finish. An idle one is waiting on
        code that has already returned control to somewhere else &mdash; a queue worker
        between jobs, a request handler that never reached <code>return</code> inside the
        closure &mdash; and it will stay open until that code comes back.
    </p>

    <h3>Locks, not just visibility</h3>

    <p>
        A long transaction is not only a vacuum problem. <code>SELECT ... FOR UPDATE</code>
        (Eloquent's <code>lockForUpdate()</code>) takes row locks that are held until the
        transaction ends, so a slow closure with a locked row in it blocks every other writer
        that wants that row for as long as the closure keeps running &mdash; a queue, an HTTP
        call, anything.
    </p>

    <h3>Three timeouts that bound three different things</h3>

    <p>
        <code>lock_timeout</code> bounds how long a single statement will wait to
        <b>acquire</b> a lock before giving up. <code>statement_timeout</code> bounds how
        long a single statement is allowed to <b>run</b>, lock or no lock.
        <code>idle_in_transaction_session_timeout</code> bounds how long a transaction may
        sit <b>open with nothing running</b> before PostgreSQL terminates the session for it.
        None of the three substitutes for another: a slow closure that runs one query and
        then calls an API is bounded by none of them until it goes idle, at which point only
        the third one catches it.
    </p>

    <h3>Replicas hold the same horizon too</h3>

    <p>
        A physical replication slot guarantees the primary keeps every WAL segment a replica
        might still need, and with <code>hot_standby_feedback</code> on, a long-running
        <code>SELECT</code> on the replica reports its own snapshot back to the primary,
        which folds it into the primary's horizon the same way a local transaction would.
        A read-only reporting query left running against a standby can therefore stop vacuum
        on the primary just as effectively as a forgotten transaction can, for exactly the
        same underlying reason.
    </p>
</div>
