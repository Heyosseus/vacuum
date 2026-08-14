"""Reframes the two real screenshots the README keeps.

Raw captures arrive at whatever width the window happened to be, with whatever
the scroll position happened to cut through. This repairs both, then sets each
one on the same dark plate the generated art uses, so the whole README reads as
one set rather than as a folder of captures.

    python art/reframe.py

Sources are read from art/source/ and left untouched.
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw

ART = Path(__file__).parent
SOURCE = ART / "source"

BG_TOP = (14, 15, 18)
BG_BOT = (21, 23, 28)
LINE = (46, 49, 56)

WIDTH = 1600
PAD = 28
RADIUS = 10


def plate(w: int, h: int) -> Image.Image:
    column = Image.new("RGB", (1, h))
    px = column.load()
    for y in range(h):
        t = y / max(1, h - 1)
        px[0, y] = tuple(int(BG_TOP[i] + (BG_BOT[i] - BG_TOP[i]) * t) for i in range(3))
    return column.resize((w, h))


def frame(shot: Image.Image, name: str) -> None:
    inner = WIDTH - PAD * 2
    h = round(shot.height * inner / shot.width)
    shot = shot.convert("RGB").resize((inner, h), Image.LANCZOS)

    mask = Image.new("L", (inner, h), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, inner - 1, h - 1], radius=RADIUS, fill=255)

    canvas = plate(WIDTH, h + PAD * 2)
    canvas.paste(shot, (PAD, PAD), mask)

    ImageDraw.Draw(canvas).rounded_rectangle(
        [PAD, PAD, PAD + inner - 1, PAD + h - 1],
        radius=RADIUS,
        outline=LINE,
        width=1,
    )

    out = ART / name
    canvas.save(out)
    print(f"wrote {out}  {canvas.width}x{canvas.height}")


def blade() -> None:
    """The capture is scrolled, so it cuts through a findings row.

    Drop the partial row and set the keyboard-hint bar back underneath the last
    card that is whole, which is where it sits on screen anyway.
    """
    src = Image.open(SOURCE / "blade-overview.png")
    body = src.crop((0, 0, src.width, 900))
    status = src.crop((0, 924, src.width, src.height))

    joined = Image.new("RGBA", (src.width, body.height + status.height))
    joined.paste(body, (0, 0))
    joined.paste(status, (0, body.height))

    frame(joined, "blade-overview.png")


def filament() -> None:
    """A sliver of the collapsed sidebar sits in the first few columns."""
    src = Image.open(SOURCE / "filament-overview.png")
    frame(src.crop((10, 0, src.width, src.height)), "filament-overview.png")


if __name__ == "__main__":
    blade()
    filament()
