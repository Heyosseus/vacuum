from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H = 2560, 1440

# --- palette: Vacuum's terminal dashboard ---
BG_TOP  = (14, 15, 18)
BG_BOT  = (21, 23, 28)
AMBER   = (224, 164, 59)
CORAL   = (217, 107, 92)
CELL_D  = (34, 36, 41)      # empty grid cell
WHITE   = (237, 234, 227)   # warm off-white
GRAY    = (121, 126, 135)

MONO_B = "C:/Windows/Fonts/consolab.ttf"
MONO_R = "C:/Windows/Fonts/consola.ttf"

SCORE = 84          # health shown in the signature grid
GRADE = "GRADE B"


def gradient_bg():
    bg = Image.new("RGB", (W, H))
    px = bg.load()
    for y in range(H):
        t = y / H
        row = tuple(int(BG_TOP[i] + (BG_BOT[i]-BG_TOP[i]) * t) for i in range(3))
        for x in range(W):
            px[x, y] = row
    return bg


def draw_health_grid(d, ox, oy, cols=10, rows=10, cell=54, gap=10):
    """10x10 = 100 cells. First SCORE amber, remainder coral-then-dark."""
    n = 0
    for r in range(rows):
        for c in range(cols):
            n += 1
            x0 = ox + c * (cell + gap)
            y0 = oy + r * (cell + gap)
            if n <= SCORE:
                fill = AMBER
            elif n <= 100:
                fill = CORAL if n <= SCORE + (100 - SCORE) else CELL_D
            else:
                fill = CELL_D
            d.rounded_rectangle([x0, y0, x0+cell, y0+cell], radius=6, fill=fill)
    return cols * (cell + gap) - gap, rows * (cell + gap) - gap


def build():
    bg = gradient_bg()

    # ambient amber glow, lower-right, behind the grid
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(glow).ellipse([W-1250, H-980, W+180, H+180], fill=(224, 164, 59, 34))
    glow = glow.filter(ImageFilter.GaussianBlur(190))
    bg = Image.alpha_composite(bg.convert("RGBA"), glow).convert("RGB")

    d = ImageDraw.Draw(bg, "RGBA")

    # faint dotted grid, top-left, terminal texture
    for gy in range(96, 96 + 8*44, 44):
        for gx in range(150, 150 + 8*44, 44):
            d.ellipse([gx, gy, gx+5, gy+5], fill=(255, 255, 255, 10))

    # ---------- RIGHT: health-grid signature ----------
    grid_w = 10*54 + 9*10          # 630
    grid_h = grid_w
    gx = W - grid_w - 250
    gy = (H - grid_h) // 2 + 20
    draw_health_grid(d, gx, gy, cell=54, gap=10)

    # label above grid
    lab_f = ImageFont.truetype(MONO_B, 30)
    lab = "H E A L T H"
    d.text((gx + 2, gy - 58), lab, font=lab_f, fill=GRAY)

    # big score + grade to the LEFT of the grid
    score_f = ImageFont.truetype(MONO_B, 230)
    grade_f = ImageFont.truetype(MONO_B, 40)
    s_txt = str(SCORE)
    s_bbox = d.textbbox((0, 0), s_txt, font=score_f)
    s_w = s_bbox[2]-s_bbox[0]
    s_h = s_bbox[3]-s_bbox[1]
    num_x = gx - 60 - s_w
    num_y = gy + (grid_h - s_h)//2 - s_bbox[1] - 30
    d.text((num_x, num_y), s_txt, font=score_f, fill=WHITE)
    d.text((num_x + 4, num_y + s_bbox[1] + s_h + 24), GRADE, font=grade_f, fill=AMBER)

    # ---------- LEFT: type ----------
    tx = 150
    kick_f  = ImageFont.truetype(MONO_B, 34)
    title_f = ImageFont.truetype(MONO_B, 200)
    sub_f   = ImageFont.truetype(MONO_R, 46)

    kicker = "postgresql  ·  filament plugin"
    title  = "vacuum"
    subs = [
        "Wraparound, autovacuum, bloat, cache-hit ratio,",
        "dead tuples, stale stats, wasted indexes — each",
        "with the exact fix. Shown, never run.",
    ]

    t_bbox = title_f.getbbox(title)
    t_h = t_bbox[3] - t_bbox[1]
    line_h = 66
    block_h = 34 + 46 + t_h + 46 + 14 + 40 + len(subs)*line_h
    y = (H - block_h)//2

    # kicker, amber
    d.text((tx, y), kicker, font=kick_f, fill=AMBER)
    y += 34 + 46

    # title
    d.text((tx, y - t_bbox[1]), title, font=title_f, fill=WHITE)
    y += t_h + 46

    # amber underline
    d.rounded_rectangle([tx, y, tx + 250, y + 12], radius=6, fill=AMBER)
    y += 14 + 40

    for ln in subs:
        d.text((tx, y), ln, font=sub_f, fill=GRAY)
        y += line_h

    main = "C:/Users/rrukhadze/vacuum/art/upload/vacuum-filament-main.jpg"
    thumb = "C:/Users/rrukhadze/vacuum/art/upload/vacuum-filament-thumbnail.jpg"
    bg.save(main, "JPEG", quality=94)
    bg.resize((1920, 1080), Image.LANCZOS).save(thumb, "JPEG", quality=94)
    print("saved", main, bg.size)
    print("saved", thumb, (1920, 1080))


build()
