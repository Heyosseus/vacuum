{{-- Band one of the json-columns lesson. The point is not "json vs jsonb, pick jsonb" as
     a slogan -- it is that Laravel never made that choice for you, and the index most
     people reach for once they have a jsonb column does not do what they expect. --}}

<p>
    Add <code>'payload' =&gt; 'array'</code> or <code>'payload' =&gt; 'json'</code> to a
    model's <code>$casts</code>, and Eloquent will happily serialize a PHP array into a JSON
    document on save and parse it back out on read. What it will not do is decide how
    PostgreSQL stores that document. That decision was already made, back in the migration
    that wrote <code>$table-&gt;json('payload')</code> or
    <code>$table-&gt;jsonb('payload')</code> &mdash; two different column types that look
    interchangeable from the Eloquent side and are not, underneath, the same thing at all.
</p>

<p>
    A <b>json</b> column stores exactly the text you sent it, byte for byte, including
    whatever whitespace and key order it arrived in. Every time anything reads it, PostgreSQL
    reparses that text from scratch &mdash; like a sealed letter you have to slit open and
    read in full each time, even if all you wanted was the postmark. A <b>jsonb</b> column
    stores a parsed, binary form instead: the document arrives already sorted into a folder,
    each field filed where PostgreSQL can find it again without reading the rest. That is why
    jsonb is what you almost always want, and why the difference is not cosmetic &mdash; one
    of them re-does the same work on every access, forever.
</p>

<p>
    Here is the trap even people who chose jsonb correctly fall into: an ordinary index on
    the column does not help you search <b>inside</b> the document. <code>where('data-&gt;status',
    'active')</code> asks PostgreSQL to look at one field buried in the document, but a plain
    B-tree index on <code>data</code> only ever indexes the document as a single, whole value
    &mdash; useful if you are looking for that exact document again, useless for looking
    inside a different one. Querying inside a jsonb column needs a different kind of index
    entirely.
</p>

<pre>create index concurrently on orders using gin (data);</pre>

<p>
    A <b>GIN</b> index indexes every key and value inside the document, so it can answer
    containment and existence queries without a table scan. The alternative, when you always
    query the same key, is a <b>B-tree on that one expression</b> &mdash;
    <code>((data-&gt;&gt;'status'))</code> &mdash; which is far smaller than a GIN index over
    the whole document and just as fast for that one predicate. Neither one is free, and
    neither is what a plain <code>create index on orders (data)</code> gives you.
</p>

<button type="button" class="why" aria-expanded="false" data-why>what that looks like underneath</button>

<div class="impact" data-impact hidden>
    <h3>jsonb_ops versus jsonb_path_ops</h3>

    <p>
        <code>gin (data)</code> defaults to the <code>jsonb_ops</code> operator class, which
        indexes every key and every value and supports the widest range of operators --
        containment (<code>@&gt;</code>), existence (<code>?</code>, <code>?|</code>,
        <code>?&amp;</code>) and path queries alike. <code>gin (data jsonb_path_ops)</code>
        indexes only the values, hashed together with their path, which makes the index
        smaller and lookups faster but restricts it to containment queries alone --
        <code>?</code> stops working against a <code>jsonb_path_ops</code> index entirely.
        Choosing it is a real trade against the queries the column actually needs to serve,
        not a strictly-better setting.
    </p>

    <h3><code>-&gt;</code> and <code>-&gt;&gt;</code> are not interchangeable to the planner</h3>

    <p>
        <code>data-&gt;'status'</code> returns <code>jsonb</code>; <code>data-&gt;&gt;'status'</code>
        returns <code>text</code>. An expression index built on one is invisible to a query
        written against the other, byte for byte -- the planner matches an expression index
        by the exact expression in the query, not by what it evaluates to. An index on
        <code>((data-&gt;&gt;'status'))</code> does nothing for a query written as
        <code>data-&gt;'status' = '"active"'</code>, and the mismatch produces no error, only
        a query that quietly falls back to a sequential scan.
    </p>

    <h3>jsonb does not remember what you sent it</h3>

    <p>
        Converting to jsonb is not a lossless change of format. Parsing discards
        insignificant whitespace, and where a document has the same key twice, jsonb keeps
        only the last one -- json keeps both and returns whichever a given reader's parser
        prefers. Key order is not preserved either: jsonb reorders keys internally for its
        own storage layout. A document you round-trip through a jsonb column can come back
        with different bytes than went in, even though every value is unchanged.
    </p>

    <h3>A big document can still mean a big read</h3>

    <p>
        A jsonb value past roughly 2 kB gets TOASTed -- compressed and moved to a side table,
        out of line from the row. A query that only asks for one key still has to fetch and
        decompress the whole document before it can extract that key, unless the index itself
        can answer the query without touching the row at all. A GIN index on a large,
        deeply-nested document buys you the lookup, not the detoast.
    </p>
</div>
