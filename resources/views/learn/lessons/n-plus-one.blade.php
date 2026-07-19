{{-- Band one of the n-plus-one lesson, and the one unusual thing about it: every
     other N+1 explainer on the internet is written from the application's side of
     the connection. This one is written from the database's, because that is the
     side a reader actually has evidence from once pg_stat_statements is running --
     and the database's evidence looks nothing like what the application-side
     story would lead you to expect. --}}

<p>
    You have seen this in Eloquent: <code>foreach ($orders as $order) { echo
    $order->customer->name; }</code>. <code>Order::all()</code> is one query. Then,
    for every single row in the loop, <code>$order->customer</code> triggers a
    <b>lazy load</b> &mdash; a fresh <code>select * from customers where id = ?</code>
    fired the moment the relation is touched, because Eloquent never fetched it up
    front. A hundred orders means a hundred and one queries: the one that loaded the
    orders, plus one customer lookup per row. That is N+1, and from the application's
    side it looks exactly like what it is &mdash; a loop, visibly running one query
    per iteration.
</p>

<p>
    The database never sees the loop. It cannot: each of those hundred customer
    lookups arrives on the connection as a completely ordinary, separate statement,
    identical to the last except for the id bound into it. PostgreSQL does not know
    or care that a PHP <code>foreach</code> is what produced them &mdash; it sees the
    <b>same prepared statement</b>, executed a hundred times in a row, each execution
    asking for exactly one row by its primary key. There is no artefact anywhere in
    the server marked "this came from a loop." There is only a statement, and a call
    count.
</p>

<p>
    And that call count is the whole story, because every one of those hundred
    executions is a full <b>round trip</b>: parse the statement (or find it already
    parsed), consult the plan, execute it, send the row back. None of that work
    scales with how much data comes back &mdash; a one-row lookup is cheap on every
    axis a query planner measures &mdash; so what you are paying for is latency,
    multiplied by volume, not work per query. A statement that costs a fraction of a
    millisecond on its own can still be the single most expensive thing your
    application does, purely by running it a hundred thousand times instead of once.
    That is also exactly why a dashboard sorted by the <b>slowest</b> query will
    never show it to you: nothing about any individual execution is slow.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>The plan cache hides the re-planning cost, not the trip</h3>

    <p>
        PostgreSQL does not re-plan an identical statement from scratch on every
        execution of a prepared statement within the same session; the plan is
        cached after the first few runs. That saves real work, but it saves the
        <b>planning</b> half of the round trip, not the round trip itself. The
        network hop out, the wait for a response, and the hop back happen on every
        single execution regardless of how well-worn the plan is. A hundred thousand
        cheap round trips are still a hundred thousand round trips.
    </p>

    <h3>Why <code>with()</code> turns N+1 into 2, and what it compiles to</h3>

    <p>
        <code>Order::with('customer')->get()</code> runs exactly two queries no
        matter how many orders come back: one for the orders, and one more that
        gathers every <code>customer_id</code> from the result and asks for all of
        them at once. That second query is a <code>whereIn</code> under the hood --
        <code>select * from customers where id in (?, ?, ?, ...)</code> -- with one
        bound parameter per distinct id. It is still one round trip, whatever the
        list's length, which is the entire saving: the cost stopped scaling with the
        number of rows in the collection.
    </p>

    <h3>Laravel can refuse to let this happen at all</h3>

    <p>
        <code>Model::preventLazyLoading()</code>, usually called once in a service
        provider's <code>boot()</code>, turns every lazy-loaded relation into a
        thrown exception instead of a silent extra query. It is the guard that turns
        this lesson from something you check for after the fact into something that
        fails a request in development before it ever reaches the database at all.
    </p>

    <h3>Why the loop collapses into one row here</h3>

    <p>
        pg_stat_statements does not store the hundred queries the loop actually
        ran. It <b>normalises</b> every literal value out of a statement's text into
        a placeholder -- <code>id = 42</code> and <code>id = 43</code> both become
        <code>id = $1</code> -- and keys its bookkeeping on the resulting shape, not
        the exact SQL sent. That is precisely why a hundred thousand different
        customer lookups, one per order, show up here as a single row with a call
        count of a hundred thousand rather than as a hundred thousand separate rows:
        the normalisation that makes the view usable at all is the same
        normalisation that makes an N+1 loop visible as one line.
    </p>
</div>
