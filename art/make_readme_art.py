"""Draws the README art for Vacuum.

Every image here is drawn rather than captured. That keeps the set reproducible,
keeps it consistent with the Blade dashboard's palette, and means a reader sees
the same picture whether or not anybody had a database handy the day the README
was written.

Run this file to rebuild all five:

    python art/make_readme_art.py

The two real screenshots the README keeps are handled by art/reframe.py.
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ART = Path(__file__).parent
SS = 2  # supersample factor; everything below is authored in logical pixels

# --- palette: shared with art/make_banner.py and the Blade dashboard ---
BG_TOP = (14, 15, 18)
BG_BOT = (21, 23, 28)
AMBER = (224, 164, 59)
CORAL = (217, 107, 92)
CORAL_DIM = (146, 76, 66)
CELL_D = (34, 36, 41)
WHITE = (237, 234, 227)
GRAY = (121, 126, 135)
DIM = (90, 95, 103)
TEAL = (79, 178, 134)
PANEL = (25, 27, 32)
LINE = (46, 49, 56)

MONO_B = "C:/Windows/Fonts/consolab.ttf"
MONO_R = "C:/Windows/Fonts/consola.ttf"

_fonts: dict[tuple[int, bool], ImageFont.FreeTypeFont] = {}


def font(size: float, bold: bool = False) -> ImageFont.FreeTypeFont:
    key = (int(size * SS), bold)
    if key not in _fonts:
        _fonts[key] = ImageFont.truetype(MONO_B if bold else MONO_R, key[0])
    return _fonts[key]


class Art:
    """A canvas authored in logical pixels and rendered at SS times that."""

    def __init__(self, w: int, h: int, glow: tuple[float, float, float, int] | None = None) -> None:
        self.w, self.h = w, h

        column = Image.new("RGB", (1, h * SS))
        px = column.load()
        for y in range(h * SS):
            t = y / max(1, h * SS - 1)
            px[0, y] = tuple(int(BG_TOP[i] + (BG_BOT[i] - BG_TOP[i]) * t) for i in range(3))
        img = column.resize((w * SS, h * SS))

        if glow is not None:
            gx, gy, gr, alpha = glow
            layer = Image.new("RGBA", (w * SS, h * SS), (0, 0, 0, 0))
            ImageDraw.Draw(layer).ellipse(
                [(gx - gr) * SS, (gy - gr) * SS, (gx + gr) * SS, (gy + gr) * SS],
                fill=(*AMBER, alpha),
            )
            layer = layer.filter(ImageFilter.GaussianBlur(70 * SS))
            img = Image.alpha_composite(img.convert("RGBA"), layer).convert("RGB")

        self.img = img
        self.d = ImageDraw.Draw(img, "RGBA")

    # --- primitives -------------------------------------------------------

    def text(self, x, y, s, size=16, bold=False, fill=WHITE, anchor=None):
        self.d.text((x * SS, y * SS), s, font=font(size, bold), fill=fill, anchor=anchor)

    def width_of(self, s, size, bold=False) -> float:
        return self.d.textlength(s, font=font(size, bold)) / SS

    def rect(self, x, y, w, h, r=8, fill=None, outline=None, width=1):
        self.d.rounded_rectangle(
            [x * SS, y * SS, (x + w) * SS, (y + h) * SS],
            radius=r * SS,
            fill=fill,
            outline=outline,
            width=max(1, int(width * SS)),
        )

    def line(self, x1, y1, x2, y2, fill=LINE, width=1):
        self.d.line([x1 * SS, y1 * SS, x2 * SS, y2 * SS], fill=fill, width=max(1, int(width * SS)))

    def dot(self, x, y, r, fill=None, outline=None, width=1):
        self.d.ellipse(
            [(x - r) * SS, (y - r) * SS, (x + r) * SS, (y + r) * SS],
            fill=fill,
            outline=outline,
            width=max(1, int(width * SS)),
        )

    def arrow(self, x1, y, x2, colour=LINE):
        """A horizontal connector with a solid head."""
        head = 9
        self.line(x1, y, x2 - head, y, fill=colour, width=1.5)
        self.d.polygon(
            [
                ((x2 - head) * SS, (y - 5) * SS),
                (x2 * SS, y * SS),
                ((x2 - head) * SS, (y + 5) * SS),
            ],
            fill=colour,
        )

    def panel(self, x, y, w, h, r=10):
        self.rect(x, y, w, h, r=r, fill=PANEL, outline=LINE, width=1)

    def chip(self, x, y, label, colour, size=12):
        pad = 9
        w = self.width_of(label, size, True) + pad * 2
        h = size + 12
        self.rect(x, y, w, h, r=h / 2, fill=(*colour, 34), outline=(*colour, 110), width=1)
        self.text(x + pad, y + h / 2, label, size=size, bold=True, fill=colour, anchor="lm")
        return w

    def save(self, name: str) -> None:
        out = ART / name
        self.img.resize((self.w, self.h), Image.LANCZOS).save(out, "PNG")
        print(f"wrote {out}  {self.w}x{self.h}")


def health_grid(a: Art, x, y, cell, gap, cells):
    for i, colour in enumerate(cells):
        row, col = divmod(i, 10)
        a.rect(
            x + col * (cell + gap),
            y + row * (cell + gap),
            cell,
            cell,
            r=max(2, cell / 7),
            fill=colour,
        )
    return 10 * cell + 9 * gap


def wrap(a: Art, text: str, size: float, limit: float, bold=False) -> list[str]:
    lines, current = [], ""
    for word in text.split():
        trial = f"{current} {word}".strip()
        if a.width_of(trial, size, bold) > limit and current:
            lines.append(current)
            current = word
        else:
            current = trial
    if current:
        lines.append(current)
    return lines


# ---------------------------------------------------------------------------
# 1. hero
# ---------------------------------------------------------------------------


def hero() -> None:
    W, H = 1600, 470
    a = Art(W, H, glow=(1330, 430, 420, 28))

    for gy in range(48, 48 + 5 * 26, 26):
        for gx in range(90, 90 + 6 * 26, 26):
            a.dot(gx, gy, 1.6, fill=(255, 255, 255, 16))

    cell, gap = 26, 6
    gw = 10 * cell + 9 * gap
    gx = W - 90 - gw
    gy = (H - gw) // 2 + 12
    score = 84
    cells = [AMBER if i < score else (CORAL if i < 94 else CELL_D) for i in range(100)]
    health_grid(a, gx, gy, cell, gap, cells)
    a.text(gx + 1, gy - 28, "H E A L T H", size=14, bold=True, fill=GRAY)

    number = str(score)
    nw = a.width_of(number, 96, True)
    nx = gx - 50 - nw
    a.text(nx, gy + gw / 2 - 18, number, size=96, bold=True, fill=WHITE, anchor="lm")
    a.text(nx + 4, gy + gw / 2 + 52, "GRADE B", size=18, bold=True, fill=AMBER, anchor="lm")

    tx, y = 90, 116
    a.text(tx, y, "postgresql  ·  laravel  ·  filament", size=18, bold=True, fill=AMBER)
    y += 44
    a.text(tx, y, "vacuum", size=92, bold=True, fill=WHITE)
    y += 118
    a.rect(tx, y, 118, 6, r=3, fill=AMBER)
    y += 34
    for ln in (
        "Wraparound, bloat, dead tuples, stale statistics,",
        "cache-hit ratio, wasted indexes — each with the",
        "exact statement that fixes it. Shown, never run.",
    ):
        a.text(tx, y, ln, size=20, fill=GRAY)
        y += 32

    a.save("hero.png")


# ---------------------------------------------------------------------------
# 2. how it works
# ---------------------------------------------------------------------------

CATALOGS = [
    ("pg_stat_user_tables", False),
    ("pg_stat_user_indexes", False),
    ("pg_stat_activity", False),
    ("pg_stat_database", False),
    ("pg_stat_statements", True),
    ("pg_class", False),
]

RULES_LEFT = [
    "wraparound",
    "multixact-wraparound",
    "autovacuum-disabled",
    "dead-tuples",
    "stale-statistics",
    "table-bloat",
    "unused-index",
]
RULES_RIGHT = [
    "duplicate-index",
    "invalid-index",
    "cache-hit-ratio",
    "idle-in-transaction",
    "blocked-session",
    "slow-statement",
]


def how_it_works() -> None:
    W, H = 1600, 650
    a = Art(W, H)

    caption = (
        "Vacuum reads six catalogs PostgreSQL already maintains about itself, "
        "runs thirteen rules over them, and hands you the statement."
    )
    a.text(W / 2, 54, caption, size=17, fill=GRAY, anchor="mm")

    top, ph = 120, 380
    ax, aw = 70, 400
    bx, bw = 540, 460
    cx, cw = 1070, 460

    a.panel(ax, top, aw, ph)
    a.panel(bx, top, bw, ph)
    a.panel(cx, top, cw, ph)
    a.arrow(478, top + ph / 2, 532, colour=DIM)
    a.arrow(1008, top + ph / 2, 1062, colour=DIM)

    # --- A: the catalogs
    y = top + 24
    a.text(ax + 22, y, "WHAT POSTGRESQL KNOWS", size=12, bold=True, fill=GRAY)
    y += 34
    for name, optional in CATALOGS:
        a.text(ax + 22, y, name, size=16, fill=AMBER)
        if optional:
            a.text(ax + 22 + a.width_of(name, 16) + 12, y + 2, "optional", size=12, fill=DIM)
        y += 30
    y += 12
    a.chip(ax + 22, y, "read only  ·  always rolled back", TEAL, size=12)
    y += 42
    a.text(ax + 22, y, "No extension and no superuser", size=13, fill=DIM)
    a.text(ax + 22, y + 20, "required to read any of it.", size=13, fill=DIM)

    # --- B: the rules
    y = top + 24
    a.text(bx + 22, y, "THIRTEEN RULES", size=12, bold=True, fill=GRAY)
    y += 34
    for column, rules in ((bx + 22, RULES_LEFT), (bx + 236, RULES_RIGHT)):
        ry = y
        for rule in rules:
            a.dot(column + 3, ry + 8, 3, fill=AMBER)
            a.text(column + 14, ry, rule, size=14, fill=WHITE)
            ry += 27
    y += 7 * 27 + 16
    a.text(bx + 22, y, "A rule is handed one value object", size=13, fill=DIM)
    a.text(bx + 22, y + 20, "and returns a finding or nothing.", size=13, fill=DIM)
    a.text(bx + 22, y + 40, "Add your own; the advisor picks it up.", size=13, fill=DIM)

    # --- C: the finding
    y = top + 24
    a.text(cx + 22, y, "WHAT YOU GET BACK", size=12, bold=True, fill=GRAY)
    y += 32
    a.chip(cx + 22, y, "WARNING", AMBER, size=12)
    a.text(cx + 130, y + 12, "unused-index", size=13, fill=DIM, anchor="lm")
    y += 42
    a.text(cx + 22, y, "public.lead_items_name_trgm", size=16, bold=True, fill=WHITE)
    y += 30
    summary = (
        "No query has used this index for as long as "
        "PostgreSQL has been counting. It occupies 48.8 MB."
    )
    for ln in wrap(a, summary, 13.5, cw - 44):
        a.text(cx + 22, y, ln, size=13.5, fill=GRAY)
        y += 21
    y += 14
    a.rect(cx + 22, y, cw - 44, 36, r=6, fill=(14, 15, 18), outline=LINE, width=1)
    a.text(
        cx + 34,
        y + 18,
        'DROP INDEX CONCURRENTLY "public"."lead_items_name_trgm";',
        size=12,
        fill=TEAL,
        anchor="lm",
    )
    y += 56
    a.line(cx + 22, y, cx + cw - 22, y)
    y += 22
    a.text(cx + 22, y, "60 / 100", size=20, bold=True, fill=WHITE)
    a.text(cx + 132, y + 6, "GRADE D", size=13, bold=True, fill=AMBER)
    a.text(cx + 22, y + 30, "computed from the findings themselves", size=13, fill=DIM)

    # --- the promise
    by, bh = 552, 62
    a.rect(70, by, 1460, bh, r=10, fill=(*TEAL, 20), outline=(*TEAL, 70), width=1)
    a.rect(70, by, 4, bh, r=2, fill=TEAL)
    a.text(
        100,
        by + bh / 2,
        "The statement is shown, and copied with one click. Vacuum has no code path that runs it.",
        size=17,
        bold=True,
        fill=TEAL,
        anchor="lm",
    )

    a.save("how-it-works.png")


# ---------------------------------------------------------------------------
# 3. scoring
# ---------------------------------------------------------------------------

DEDUCTIONS = [("unused-index", 25, CORAL), ("cache-hit-ratio", 15, CORAL_DIM)]


def scoring() -> None:
    W, H = 1600, 580
    a = Art(W, H)

    a.text(
        W / 2,
        50,
        "The grade is computed from the findings, so it can never disagree with the list beneath it.",
        size=17,
        fill=GRAY,
        anchor="mm",
    )

    score = 100 - sum(cost for _, cost, _ in DEDUCTIONS)

    cells = []
    for _ in range(score):
        cells.append(AMBER)
    for _, cost, colour in DEDUCTIONS:
        cells.extend([colour] * cost)

    cell, gap = 30, 7
    gx, gy = 470, 126
    gw = health_grid(a, gx, gy, cell, gap, cells)

    number = str(score)
    nw = a.width_of(number, 130, True)
    a.text(gx - 70 - nw, gy + gw / 2 - 16, number, size=130, bold=True, fill=WHITE, anchor="lm")
    a.text(gx - 66 - nw, gy + gw / 2 + 66, "GRADE D", size=20, bold=True, fill=AMBER, anchor="lm")

    lx = gx + gw + 78
    y = gy + 6
    a.text(lx, y, "100", size=22, bold=True, fill=WHITE)
    a.text(lx + 70, y + 6, "everything inside its thresholds", size=14, fill=GRAY)
    y += 46
    for rule, cost, colour in DEDUCTIONS:
        a.rect(lx, y + 5, 14, 14, r=3, fill=colour)
        a.text(lx + 28, y, f"−{cost}", size=22, bold=True, fill=colour)
        a.text(lx + 98, y + 6, rule, size=14, fill=GRAY)
        y += 46
    a.line(lx, y + 4, lx + 430, y + 4)
    y += 24
    a.text(lx, y, str(score), size=22, bold=True, fill=AMBER)
    a.text(lx + 70, y + 6, "grade D", size=14, fill=AMBER)

    a.text(
        gx,
        gy + gw + 34,
        "one cell is one point  ·  amber is what the database still has  ·  coral is what a finding cost it",
        size=13,
        fill=DIM,
    )

    a.save("scoring.png")


# ---------------------------------------------------------------------------
# 4. the console's safety model
# ---------------------------------------------------------------------------

LAYERS = [
    (
        "1",
        "the keyword check",
        "Statements that do not begin with a word that reads are turned away.",
        "a courtesy",
        GRAY,
        "walks past",
        CORAL,
    ),
    (
        "2",
        "the read-only transaction",
        "beginTransaction() · SET TRANSACTION READ ONLY · statement_timeout · always ROLLBACK",
        "PostgreSQL enforces this",
        TEAL,
        "refused",
        TEAL,
    ),
    (
        "3",
        "the role it connects as",
        "Point VACUUM_CONNECTION at a role that owns nothing and has dblink, "
        "pg_read_file and pg_terminate_backend revoked.",
        "what actually bounds it",
        AMBER,
        "",
        DIM,
    ),
]


def safety() -> None:
    W, H = 1600, 730
    a = Art(W, H)

    a.text(
        W / 2,
        46,
        "What makes the SQL console safe is not the keyword check.",
        size=17,
        fill=GRAY,
        anchor="mm",
    )

    # the statement that defeats a keyword filter
    sx, sy, sw, sh = 200, 106, 1330, 56
    a.rect(sx, sy, sw, sh, r=8, fill=(14, 15, 18), outline=LINE, width=1)
    a.text(
        sx + 20,
        sy + sh / 2,
        'WITH written AS (INSERT INTO orders (id) VALUES (1) RETURNING *) SELECT * FROM written',
        size=15,
        fill=WHITE,
        anchor="lm",
    )
    a.text(sx, sy - 22, "A STATEMENT THAT WRITES", size=12, bold=True, fill=CORAL)

    top, bh, gap = 210, 130, 26
    rail = 148

    # The statement's own path: it leaves the box, sails through layer one, and
    # stops dead in layer two. The rail ends where the statement does.
    a.line(rail, sy + sh, rail, top + (bh + gap) + bh / 2, fill=CORAL, width=2)

    for i, (num, name, detail, verdict, colour, mark, mark_colour) in enumerate(LAYERS):
        y = top + i * (bh + gap)

        a.panel(sx, y, sw, bh)
        a.rect(sx, y, 4, bh, r=2, fill=colour)

        a.text(sx + 26, y + 34, num, size=15, bold=True, fill=DIM, anchor="lm")
        a.text(sx + 54, y + 34, name, size=21, bold=True, fill=WHITE, anchor="lm")

        for j, ln in enumerate(wrap(a, detail, 14.5, sw - 380)):
            a.text(sx + 54, y + 62 + j * 22, ln, size=14.5, fill=GRAY)

        cw = a.width_of(verdict, 12, True) + 18
        a.chip(sx + sw - 26 - cw, y + 26, verdict, colour, size=12)

        cy = y + bh / 2
        if mark:
            a.dot(rail, cy, 9, fill=(*mark_colour, 40), outline=mark_colour, width=2)
            a.dot(rail, cy, 3.5, fill=mark_colour)
            a.text(rail - 22, cy + 22, mark, size=12, bold=True, fill=mark_colour, anchor="ra")
        else:
            a.dot(rail, cy, 6, outline=LINE, width=2)

    a.text(
        sx,
        top + 3 * (bh + gap) + 4,
        "There is a test that smuggles exactly that statement through and then asserts the table is still empty.",
        size=14,
        fill=DIM,
    )

    a.save("safety.png")


# ---------------------------------------------------------------------------
# 5. vacuum:check in a terminal
# ---------------------------------------------------------------------------

# Rendered from CheckCommand::report(), so the shape is the command's own.
CLI = [
    ("$ ", TEAL, "php artisan vacuum:check", WHITE),
    None,
    ("  ", None, "60", "bold-white", " / 100   Grade D", WHITE),
    None,
    ("  ", None, "critical  ", CORAL, "database", "bold", "  cache-hit-ratio", GRAY),
    ("           ", None, "88.7% of block reads were served from memory, against a target of 99.0%.", WHITE),
    None,
    ("  ", None, "warning   ", AMBER, "public.lead_items_name_trgm", "bold", "  unused-index", GRAY),
    ("           ", None, "No query has used this index for as long as PostgreSQL has been counting. It occupies 48.8 MB.", WHITE),
    ("           ", None, 'DROP INDEX CONCURRENTLY "public"."lead_items_name_trgm";', GRAY),
    None,
    ("  ", None, "warning   ", AMBER, "public.contacts_name_trgm", "bold", "  unused-index", GRAY),
    ("           ", None, "No query has used this index for as long as PostgreSQL has been counting. It occupies 45.0 MB.", WHITE),
    ("           ", None, 'DROP INDEX CONCURRENTLY "public"."contacts_name_trgm";', GRAY),
    None,
    ("  ", None, "warning   ", AMBER, "public.contacts_phone_trgm", "bold", "  unused-index", GRAY),
    ("           ", None, "No query has used this index for as long as PostgreSQL has been counting. It occupies 31.1 MB.", WHITE),
    ("           ", None, 'DROP INDEX CONCURRENTLY "public"."contacts_phone_trgm";', GRAY),
    None,
    ("  ", None, "… 35 more", DIM),
    None,
    ("  ", None, "unused-index............ -25", GRAY),
    ("  ", None, "cache-hit-ratio......... -15", GRAY),
    None,
    ("$ ", TEAL, "echo $?", WHITE),
    ("", None, "1", CORAL),
]


def cli_check() -> None:
    size = 14.5
    lh = 25
    pad = 26
    bar = 40

    body_lines = len(CLI)
    W = 1600
    H = int(bar + pad * 2 + body_lines * lh) + 40

    a = Art(W, H)

    # window
    wx, wy, ww, wh = 40, 26, W - 80, H - 52
    a.rect(wx, wy, ww, wh, r=12, fill=(14, 15, 18), outline=LINE, width=1)
    a.rect(wx, wy, ww, bar, r=12, fill=(24, 26, 31))
    a.rect(wx, wy + bar - 12, ww, 12, r=0, fill=(24, 26, 31))
    a.line(wx, wy + bar, wx + ww, wy + bar)
    for i, colour in enumerate(((90, 94, 102), (90, 94, 102), (90, 94, 102))):
        a.dot(wx + 24 + i * 20, wy + bar / 2, 5.5, fill=colour)
    a.text(wx + ww / 2, wy + bar / 2, "vacuum:check", size=13, fill=GRAY, anchor="mm")

    y = wy + bar + pad
    for row in CLI:
        if row is None:
            y += lh
            continue
        x = wx + pad
        parts = list(row)
        while parts:
            text, colour = parts.pop(0), parts.pop(0)
            bold = colour in ("bold", "bold-white")
            fill = WHITE if bold else (colour or GRAY)
            a.text(x, y, text, size=size, bold=bold, fill=fill)
            x += a.width_of(text, size, bold)
        y += lh

    a.save("cli-check.png")


# ---------------------------------------------------------------------------
# 6. vacuum:lint in a pipeline
# ---------------------------------------------------------------------------

WORKFLOW = [
    ("services:", DIM),
    ("  postgres: { image: postgres:17 }", GRAY),
    ("", None),
    ("steps:", DIM),
    ("  - run: php artisan migrate --force", GRAY),
    ("  - run: php artisan vacuum:lint --format=github", WHITE),
]

# The diff as a reviewer sees it: the line that introduced the finding is the
# line the finding lands on.
DIFF = [
    (" ", "12", "Schema::create('orders', function (Blueprint $table) {", GRAY, False),
    ("+", "13", "    $table->id();", GRAY, True),
    ("+", "14", "    $table->foreignId('customer_id');", WHITE, True),
    (" ", "15", "});", GRAY, False),
]


def lint_pr() -> None:
    W, H = 1600, 620
    a = Art(W, H)

    a.text(
        W / 2,
        48,
        "The database is ninety seconds old and has no rows in it. "
        "The finding still lands on the line that caused it.",
        size=17,
        fill=GRAY,
        anchor="mm",
    )

    top, ph = 108, 400
    ax, aw = 70, 520
    bx, bw = 640, 890

    a.panel(ax, top, aw, ph)
    a.panel(bx, top, bw, ph)
    a.arrow(600, top + ph / 2, 632, colour=DIM)

    # --- A: the workflow
    y = top + 26
    a.text(ax + 24, y, "IN YOUR WORKFLOW", size=12, bold=True, fill=GRAY)
    y += 36
    a.rect(ax + 24, y, aw - 48, 172, r=8, fill=(14, 15, 18), outline=LINE, width=1)
    ly = y + 20
    for line, colour in WORKFLOW:
        if line:
            a.text(ax + 40, ly, line, size=13, fill=colour)
        ly += 25
    y += 196

    a.chip(ax + 24, y, "require-dev", TEAL, size=12)
    y += 46
    for ln in (
        "No production database, no credentials, no",
        "extension and no superuser. Every rule here",
        "is answerable the moment migrate finishes.",
    ):
        a.text(ax + 24, y, ln, size=13.5, fill=DIM)
        y += 22

    # --- B: the pull request
    y = top + 26
    a.text(bx + 24, y, "ON THE PULL REQUEST", size=12, bold=True, fill=GRAY)
    y += 30
    a.text(
        bx + 24,
        y,
        "database/migrations/2024_01_11_000000_create_orders_table.php",
        size=13,
        fill=DIM,
    )
    y += 30

    for mark, number, code, colour, added in DIFF:
        if added:
            a.rect(bx + 24, y - 4, bw - 48, 26, r=4, fill=(*TEAL, 16))
        a.text(bx + 34, y, number, size=13, fill=(70, 74, 82))
        a.text(bx + 72, y, mark, size=14, bold=True, fill=TEAL if added else DIM)
        a.text(bx + 92, y, code, size=14, fill=colour)
        y += 26

    # the annotation, hung under the line that produced it
    y += 16
    ah = 152
    a.rect(bx + 92, y, bw - 140, ah, r=8, fill=(14, 15, 18), outline=(*AMBER, 90), width=1)
    a.rect(bx + 92, y, 4, ah, r=2, fill=AMBER)

    iy = y + 20
    cw = a.chip(bx + 116, iy, "WARNING", AMBER, size=11)
    a.text(bx + 116 + cw + 14, iy + 12, "unindexed-foreign-key", size=12.5, fill=DIM, anchor="lm")
    iy += 40
    a.text(
        bx + 116,
        iy,
        "orders.customer_id has a foreign key and no index behind it.",
        size=14,
        fill=WHITE,
    )
    iy += 26
    a.text(
        bx + 116,
        iy,
        "PostgreSQL indexes a primary key and creates nothing for this.",
        size=13,
        fill=GRAY,
    )
    iy += 30
    a.rect(bx + 116, iy, bw - 188, 32, r=6, fill=(21, 23, 28), outline=LINE, width=1)
    a.text(
        bx + 128,
        iy + 16,
        'CREATE INDEX CONCURRENTLY ON "public"."orders" ("customer_id");',
        size=12,
        fill=TEAL,
        anchor="lm",
    )

    # --- the verdict
    by, bh = 526, 62
    a.rect(70, by, 1460, bh, r=10, fill=(*CORAL, 20), outline=(*CORAL, 70), width=1)
    a.rect(70, by, 4, bh, r=2, fill=CORAL)
    a.text(
        100,
        by + bh / 2,
        "Exit 1. A warning from vacuum:check is a database drifting; a warning from "
        "vacuum:lint is a schema that was wrong the moment somebody typed it.",
        size=17,
        bold=True,
        fill=CORAL,
        anchor="lm",
    )

    a.save("lint-in-ci.png")


if __name__ == "__main__":
    hero()
    how_it_works()
    scoring()
    safety()
    cli_check()
    lint_pr()
