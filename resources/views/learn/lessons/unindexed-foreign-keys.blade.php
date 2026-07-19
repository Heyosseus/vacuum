{{-- Band one of the unindexed-foreign-keys lesson. It is the entry point of the
     Eloquent tier on purpose: this is the gap between "it works on my machine" and
     "it works on MySQL" that a Laravel developer hits without ever choosing
     PostgreSQL as the reason it happened. The page defines a foreign key as a
     promise before it says anything about scans, because the scan only makes sense
     once the reader understands what the database is being asked to prove. --}}

<p>
    A <b>foreign key</b> is a promise: <code>orders.customer_id</code> references
    <code>customers.id</code>, and PostgreSQL will not let you insert an order that
    points at a customer who does not exist. <code>$table->foreignId('customer_id')
    ->constrained()</code> writes exactly that promise into the schema. It is a
    constraint, not a lookup structure &mdash; it tells PostgreSQL what must always
    be true, and says nothing about how to find rows quickly. Whether anything can
    look up <code>customer_id</code> without reading the whole table is a completely
    separate question, and it is one the constraint does not answer.
</p>

<p>
    The promise runs in both directions, and the direction that costs you is the
    one you don't write: deleting a customer. Before PostgreSQL will let that
    <code>DELETE</code> proceed, it has to prove no order still points at the row
    being removed &mdash; otherwise the promise would be broken the instant the
    customer is gone. If <code>customer_id</code> has no index, the only way to
    prove that is to read <b>every row</b> in <code>orders</code> and check. A
    2-million-row orders table means a 2-million-row scan, run while holding a lock
    on the customer being deleted, every single time. The child table's own reads
    stay fast the whole time this is happening, which is exactly why nobody notices
    until the delete does.
</p>

<p>
    This is the trap for a schema learned on MySQL, which creates an index for
    every foreign key automatically, whether it needs one or not. PostgreSQL
    creates an index for a primary key and for a unique constraint, because both
    need one to enforce themselves &mdash; but a foreign key needs no index to
    enforce itself, so it gets none. A schema copied over keeps every column, every
    constraint, and every relationship, and quietly loses every one of those free
    indexes on the way. The fix is one call:
    <code>$table->foreignId('customer_id')->constrained()->index()</code>, or
    equivalently a separate <code>$table->index('customer_id')</code> anywhere
    after the column exists.
</p>

<pre>$table->foreignId('customer_id')->constrained()->index();</pre>

<button type="button" class="why" aria-expanded="false" data-why>what makes this worse, and what doesn't fix it</button>

<div class="impact" data-impact hidden>
    <h3>ON DELETE CASCADE does not help</h3>

    <p>
        It is tempting to assume <code>ON DELETE CASCADE</code> sidesteps the
        problem, since PostgreSQL is going to delete the matching rows anyway rather
        than merely check for them. It does not. Cascading still has to <b>find</b>
        the rows before it can delete them, and finding them without an index is the
        same sequential scan &mdash; now followed by however many deletes it turns
        up, all inside the same transaction, all still holding the lock on the
        parent row. A cascade turns a slow check into a slow check plus slow writes.
    </p>

    <h3>The lock is held for the whole scan</h3>

    <p>
        The row-level lock PostgreSQL takes on the customer being deleted is not
        released until the transaction that deleted it commits or rolls back. If
        that transaction also has to sequentially scan two million rows of
        <code>orders</code> to satisfy the foreign key check, every other
        transaction that wants that same customer row &mdash; an update, another
        delete, sometimes even a read under the wrong isolation level &mdash; waits
        behind it for as long as the scan takes. This is usually where the report
        comes in: not "deletes are slow" but "the app froze for four seconds."
    </p>

    <h3>A composite index only helps as a prefix</h3>

    <p>
        An index on <code>(status, customer_id)</code> looks like it covers a
        lookup on <code>customer_id</code>, and it does not: a btree index can only
        be searched efficiently from its <b>leading</b> column inward, so a query
        that only knows <code>customer_id</code> cannot use this index at all
        without scanning it end to end anyway. Only an index on
        <code>(customer_id, status)</code>, or on <code>customer_id</code> alone,
        actually serves the foreign key. If a table already has a composite index
        and the reader is unsure whether it counts, the rule is: does
        <code>customer_id</code> come first? If not, it is not covering this
        constraint, however much it looks like it should.
    </p>
</div>
