#!/usr/bin/env python3
"""GigHive Ball Cap — Design Sheet PNG Generator"""

import os, sys, math, textwrap, requests
from io import BytesIO
from PIL import Image, ImageDraw, ImageFont, ImageEnhance, ImageFilter

DIR    = os.path.dirname(os.path.abspath(__file__))
OUTPUT = os.path.join(DIR, 'gighive-hat-mockup.png')

# ── Canvas ────────────────────────────────────────────────────────────────────
W, H = 1400, 1050

# ── Palette ───────────────────────────────────────────────────────────────────
BG       = ( 18,  11,   4)
PANEL    = ( 29,  19,   8)
C_CREAM  = (237, 232, 220)
C_BLUE   = (114, 189, 209)
C_BLUE_D = ( 76, 154, 172)
C_SEAM   = (190, 178, 160)
C_WHITE  = (248, 242, 232)
C_DIM    = (170, 152, 128)
C_NAVY   = ( 26,  36,  55)
C_ACCENT = (205, 178, 128)

# ── Font helpers ──────────────────────────────────────────────────────────────
def _find_font(bold=False):
    cands = []
    if bold:
        cands = [
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf',
        ]
    else:
        cands = [
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-R.ttf',
        ]
    for p in cands:
        if os.path.exists(p):
            return p
    return None

def fnt(size, bold=False):
    p = _find_font(bold)
    if p:
        try:
            return ImageFont.truetype(p, size)
        except Exception:
            pass
    return ImageFont.load_default()

# Try to get a script font for "GigHive" text
_script_path = os.path.join(DIR, '_DancingScript-Bold.ttf')
if not os.path.exists(_script_path):
    print('Downloading script font…')
    try:
        r = requests.get(
            'https://github.com/googlefonts/dancing-script/raw/main/fonts/ttf/DancingScript-Bold.ttf',
            timeout=20
        )
        with open(_script_path, 'wb') as f:
            f.write(r.content)
        print('  OK')
    except Exception as e:
        print(f'  Failed: {e}')

def sfnt(size):
    if os.path.exists(_script_path):
        try:
            return ImageFont.truetype(_script_path, size)
        except Exception:
            pass
    return fnt(size, bold=True)

# ── Network helpers ───────────────────────────────────────────────────────────
HEADERS = {'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36'}

def fetch(url):
    r = requests.get(url, timeout=20, headers=HEADERS)
    return Image.open(BytesIO(r.content)).convert('RGBA')

# ── Fetch assets ──────────────────────────────────────────────────────────────
print('Fetching hat image…')
hat = None
try:
    data = requests.get(
        'https://www.armycrew.com/products/two-tone-pigment-dyed-washed-unstructured-baseball-cap.json',
        timeout=15, headers=HEADERS
    ).json()
    hat = fetch(data['product']['images'][3]['src'])  # Natural/Turquoise variant
    print(f'  Hat: {hat.size}')
except Exception as e:
    print(f'  Hat fetch failed: {e}')

print('Fetching GigHive logo…')
logo = None
try:
    logo = fetch('https://gighive.app/images/beelogo.png')
    print(f'  Logo: {logo.size}')
except Exception as e:
    print(f'  Logo fetch failed: {e}')

# ── Hat illustration helpers ──────────────────────────────────────────────────

def _arc_pts(cx, cy, rx, ry, a0, a1, steps=20):
    """Return list of (x,y) points along an ellipse arc."""
    pts = []
    for i in range(steps+1):
        a = math.radians(a0 + (a1-a0)*i/steps)
        pts.append((int(cx + rx*math.cos(a)), int(cy + ry*math.sin(a))))
    return pts


def _clip_to_crown(layer, crown_poly, w, h):
    """Zero out alpha of layer wherever it falls outside crown_poly."""
    import numpy as np
    mask = Image.new('L', (w, h), 0)
    ImageDraw.Draw(mask).polygon(crown_poly, fill=255)
    arr = np.array(layer)
    m   = np.array(mask)
    arr[:, :, 3] = np.minimum(arr[:, :, 3], m)
    return Image.fromarray(arr)


def draw_side_hat(w, h, face_right=True):
    """Return RGBA image of a side-view hat illustration.
    Uses UPPER arc (180→360°) so dome curves UP through the top of the ellipse."""
    img  = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    cx, cy = int(w*0.46), int(h*0.60)
    rx, ry = int(w*0.36), int(h*0.50)
    bot_y  = int(h*0.62)

    # Upper dome arc: 180°→360° passes through cy-ry (dome top)
    dome_top   = _arc_pts(cx, cy, rx, ry, 180, 360, 30)
    crown_poly = [(cx-rx, bot_y)] + dome_top + [(cx+rx, bot_y)]
    ImageDraw.Draw(img).polygon(crown_poly, fill=C_BLUE+(255,))

    # Front panel follows the arc (upper-half angle for seam position)
    if face_right:
        seam_x = int(w * 0.62)
        cos_s  = max(-1.0, min(1.0, (seam_x - cx) / rx))
        # Upper arc: 360-arccos gives angle in (270°,360°) for cos_s>0
        a_seam = 360 - math.degrees(math.acos(cos_s))
        arc_seg = _arc_pts(cx, cy, rx, ry, a_seam, 360, 12)
        # arc_seg[0] = seam top, arc_seg[-1] = right edge at cy
        front_poly = arc_seg + [(cx+rx, bot_y), (seam_x, bot_y)]
        seam_top_y = arc_seg[0][1]
        brim = [(cx-rx, bot_y), (cx+rx, bot_y),
                (int(w*.97), int(h*.74)), (int(w*.90), int(h*.83)), (int(w*.05), int(h*.73))]
        btn, text_cx = (int(w*.44), int(h*.11)), int(w*.38)
    else:
        seam_x = int(w * 0.38)
        cos_s  = max(-1.0, min(1.0, (seam_x - cx) / rx))
        # Upper arc: 360-arccos gives angle in (180°,270°) for cos_s<0
        a_seam = 360 - math.degrees(math.acos(cos_s))
        arc_seg = _arc_pts(cx, cy, rx, ry, 180, a_seam, 12)
        # arc_seg[0] = left edge at cy, arc_seg[-1] = seam top
        front_poly = [(cx-rx, bot_y)] + arc_seg + [(seam_x, bot_y)]
        seam_top_y = arc_seg[-1][1]
        brim = [(cx+rx, bot_y), (cx-rx, bot_y),
                (int(w*.03), int(h*.74)), (int(w*.10), int(h*.83)), (int(w*.95), int(h*.73))]
        btn, text_cx = (int(w*.56), int(h*.11)), int(w*.62)

    cream = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(cream).polygon(front_poly, fill=C_CREAM+(255,))
    img = Image.alpha_composite(img, cream)
    d   = ImageDraw.Draw(img)
    d.line([(seam_x, seam_top_y), (seam_x, bot_y)], fill=C_SEAM+(180,), width=2)
    d.polygon(brim, fill=C_BLUE_D+(255,))
    if face_right:
        hi = [(cx-rx,bot_y),(cx+rx,bot_y),(int(w*.90),int(h*.68)),(int(w*.05),int(h*.68))]
    else:
        hi = [(cx+rx,bot_y),(cx-rx,bot_y),(int(w*.10),int(h*.68)),(int(w*.95),int(h*.68))]
    d.polygon(hi, fill=C_BLUE+(120,))
    d.ellipse([btn[0]-4,btn[1]-4,btn[0]+4,btn[1]+4],
              fill=C_BLUE_D+(255,), outline=C_SEAM+(255,), width=1)
    img._text_cx = text_cx
    return img


def draw_back_hat(w, h):
    img = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    d   = ImageDraw.Draw(img)
    cx, cy, rx, ry = int(w*.50), int(h*.60), int(w*.33), int(h*.50)
    bot_y = int(h*.62)
    dome_top   = _arc_pts(cx, cy, rx, ry, 180, 360, 30)
    crown_poly = [(cx-rx, bot_y)] + dome_top + [(cx+rx, bot_y)]
    d.polygon(crown_poly, fill=C_BLUE+(255,))
    for sx in [int(w*.35), int(w*.65)]:
        d.line([(sx, int(h*.10)), (sx, bot_y)], fill=C_BLUE_D+(160,), width=1)
    btn = (cx, int(h*.10))
    d.ellipse([btn[0]-5,btn[1]-5,btn[0]+5,btn[1]+5],
              fill=C_CREAM+(255,), outline=C_SEAM+(255,), width=1)
    sy = bot_y
    d.rectangle([int(w*.30),sy,    int(w*.70),sy+12], fill=C_BLUE_D+(255,))
    d.rectangle([int(w*.40),sy-4,  int(w*.60),sy+16], fill=(58,115,130,255))
    d.rectangle([int(w*.46),sy-7,  int(w*.54),sy+19], fill=C_CREAM+(255,))
    brim = [(cx-rx, bot_y),(cx+rx, bot_y),(int(w*.87),int(h*.72)),(int(w*.13),int(h*.72))]
    d.polygon(brim, fill=C_BLUE_D+(200,))
    return img


def draw_front_hat(w, h):
    img = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    cx, cy, rx, ry = int(w*.50), int(h*.60), int(w*.33), int(h*.50)
    bot_y  = int(h*.62)
    dome_top   = _arc_pts(cx, cy, rx, ry, 180, 360, 30)
    crown_poly = [(cx-rx, bot_y)] + dome_top + [(cx+rx, bot_y)]
    ImageDraw.Draw(img).polygon(crown_poly, fill=C_BLUE+(255,))

    # Cream centre panel bounded by arc
    sl_x = int(w*.30); sr_x = int(w*.70)
    cos_l = max(-1.0, min(1.0, (sl_x-cx)/rx))
    cos_r = max(-1.0, min(1.0, (sr_x-cx)/rx))
    a_l = 360 - math.degrees(math.acos(cos_l))   # upper arc ~232°
    a_r = 360 - math.degrees(math.acos(cos_r))   # upper arc ~307°
    cream_arc  = _arc_pts(cx, cy, rx, ry, a_l, a_r, 14)
    cream_poly = cream_arc + [(sr_x, bot_y), (sl_x, bot_y)]
    cream = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(cream).polygon(cream_poly, fill=C_CREAM+(255,))
    img = Image.alpha_composite(img, cream)
    d = ImageDraw.Draw(img)

    sl_top_y = int(cy + ry*math.sin(math.radians(a_l)))
    sr_top_y = int(cy + ry*math.sin(math.radians(a_r)))
    d.line([(sl_x, sl_top_y),(sl_x, bot_y)], fill=C_SEAM+(220,), width=2)
    d.line([(sr_x, sr_top_y),(sr_x, bot_y)], fill=C_SEAM+(220,), width=2)
    d.ellipse([cx-5,int(h*.10)-5,cx+5,int(h*.10)+5],
              fill=C_BLUE_D+(255,), outline=C_SEAM+(255,), width=1)
    brim = [(cx-rx,bot_y),(cx+rx,bot_y),
            (int(w*.90),int(h*.74)),(int(w*.82),int(h*.82)),
            (int(w*.18),int(h*.82)),(int(w*.10),int(h*.74))]
    d.polygon(brim, fill=C_BLUE_D+(255,))
    hi = [(cx-rx,bot_y),(cx+rx,bot_y),(int(w*.88),int(h*.68)),(int(w*.12),int(h*.68))]
    d.polygon(hi, fill=C_BLUE+(120,))
    return img

# ── Paste RGBA helper ─────────────────────────────────────────────────────────
def paste_rgba(canvas, img, xy):
    if img.mode != 'RGBA':
        img = img.convert('RGBA')
    canvas.paste(img, xy, img)

# ── Build canvas ──────────────────────────────────────────────────────────────
canvas = Image.new('RGB', (W, H), BG)
draw   = ImageDraw.Draw(canvas)

# Panel rects  (x1, y1, x2, y2)
HERO = (  0,   0, 780, 700)
LS   = (792,   0,   W, 232)   # Left Side
RS   = (792, 244,   W, 476)   # Right Side
BK   = (792, 488,   W, 700)   # Back
EM   = (  0, 712, 375,   H)   # Embroidery Detail
FV   = (387, 712, 762,   H)   # Front View
DS   = (792, 712,   W,   H)   # Design Summary

def fill(r, c): draw.rectangle(r, fill=c)
def pw(r): return r[2]-r[0]
def ph(r): return r[3]-r[1]

for r in [HERO, LS, RS, BK, EM, FV, DS]:
    fill(r, PANEL)

# Subtle dark separators already showing as BG between panels (gap=12 px)

# ── HERO ─────────────────────────────────────────────────────────────────────
# Title block
tx, ty = HERO[0]+26, HERO[1]+22
draw.text((tx, ty),      "GIGHIVE",            font=fnt(52, True), fill=C_WHITE)
draw.text((tx, ty+60),   "— FOUNDED 2026 —",   font=fnt(17),       fill=C_ACCENT)
draw.text((tx, ty+88),   "MODERN UNSTRUCTURED", font=fnt(14),       fill=C_DIM)
draw.text((tx, ty+108),  "BASEBALL CAP",         font=fnt(14),       fill=C_DIM)

if hat:
    # Scale hat to fill hero panel below title
    ht_max_w = pw(HERO) - 20
    ht_max_h = ph(HERO) - 155
    ratio = hat.width / hat.height
    if ht_max_w / ht_max_h > ratio:
        nh = ht_max_h; nw = int(nh * ratio)
    else:
        nw = ht_max_w; nh = int(nw / ratio)
    hat_rs = hat.resize((nw, nh), Image.LANCZOS)
    hx = HERO[0] + (pw(HERO)-nw)//2
    hy = HERO[1] + 148
    # Paste with mask (transparent bg from product shot)
    canvas.paste(hat_rs.convert('RGB'), (hx, hy))

    # Overlay GigHive logo centered on cream front panel
    # Hat image 3 (1000x717): cream panel center ~x=46%, y=35% of hat
    if logo:
        lsz = int(nw * 0.22)
        lr  = logo.resize((lsz, lsz), Image.LANCZOS)
        lx  = hx + int(nw * 0.35)   # center 0.46 minus half-logo
        ly  = hy + int(nh * 0.22)
        paste_rgba(canvas, lr, (lx, ly))

        # "GIGHIVE" text on front panel below logo (above brim)
        draw.text((hx + int(nw*0.455), hy + int(nh*0.55)),
                  "GIGHIVE", font=fnt(int(nw*0.038), True),
                  fill=C_NAVY, anchor='mm')

def hat_in_panel(panel_rect, draw_fn, label, text_fn=None, label_on_hat=False):
    """Draw hat illustration constrained to ~2:1 aspect ratio, centred in panel."""
    draw.text((panel_rect[0]+14, panel_rect[1]+10), label, font=fnt(13, True), fill=C_DIM)
    pw_ = pw(panel_rect)
    ph_ = ph(panel_rect) - 30
    # constrain to 1.6:1 (gives a taller, more hat-like silhouette)
    HAT_RATIO = 1.6
    hat_h = min(ph_, int(pw_ / HAT_RATIO))
    hat_w = int(hat_h * HAT_RATIO)
    hat_img = draw_fn(hat_w, hat_h)
    if text_fn:
        text_fn(hat_img, hat_w, hat_h)
    # centre in panel
    ox = panel_rect[0] + (pw_ - hat_w) // 2
    oy = panel_rect[1] + 30 + (ph_ - hat_h) // 2
    paste_rgba(canvas, hat_img, (ox, oy))

# ── LEFT SIDE ────────────────────────────────────────────────────────────────
def ls_text(img, w, h):
    d = ImageDraw.Draw(img)
    tcx = getattr(img, '_text_cx', int(w*.62))
    d.text((tcx, int(h*.40)), "GigHive", font=sfnt(int(h*.18)),
           fill=C_WHITE, anchor='mm')
hat_in_panel(LS, lambda w,h: draw_side_hat(w, h, face_right=True),  "LEFT SIDE", ls_text)

# ── RIGHT SIDE ───────────────────────────────────────────────────────────────
def rs_text(img, w, h):
    d = ImageDraw.Draw(img)
    tcx = getattr(img, '_text_cx', int(w*.38))
    d.text((tcx, int(h*.35)), "FOUNDED",    font=fnt(int(h*.12), True), fill=C_WHITE, anchor='mm')
    d.text((tcx, int(h*.50)), "— 2026 —", font=fnt(int(h*.09)),        fill=C_WHITE, anchor='mm')
hat_in_panel(RS, lambda w,h: draw_side_hat(w, h, face_right=False), "RIGHT SIDE", rs_text)

# ── BACK ─────────────────────────────────────────────────────────────────────
def bk_text(img, w, h):
    d = ImageDraw.Draw(img)
    d.text((w//2, int(h*.30)), "GIGHIVE", font=fnt(int(h*.14), True), fill=C_CREAM, anchor='mm')
hat_in_panel(BK, draw_back_hat, "BACK", bk_text)

# ── EMBROIDERY DETAIL ────────────────────────────────────────────────────────
draw.text((EM[0]+14, EM[1]+10), "EMBROIDERY DETAIL", font=fnt(13, True), fill=C_DIM)
if logo:
    esz = min(pw(EM)-50, ph(EM)-55)
    em_logo = logo.resize((esz, esz), Image.LANCZOS)
    # Place on a circular blue background
    bg_circle = Image.new('RGBA', (esz, esz), (0,0,0,0))
    bc_d = ImageDraw.Draw(bg_circle)
    bc_d.ellipse([0,0,esz,esz], fill=C_BLUE+(255,))
    ex = EM[0] + (pw(EM)-esz)//2
    ey = EM[1] + 35 + (ph(EM)-45-esz)//2
    canvas.paste(bg_circle.convert('RGB'), (ex, ey))
    paste_rgba(canvas, em_logo, (ex, ey))

# ── FRONT VIEW ───────────────────────────────────────────────────────────────
draw.text((FV[0]+14, FV[1]+10), "FRONT VIEW", font=fnt(13, True), fill=C_DIM)
fvw, fvh = pw(FV), ph(FV)-30
fv_hat = draw_front_hat(fvw, fvh)
if logo:
    flsz = int(fvw * 0.34)
    fl   = logo.resize((flsz, flsz), Image.LANCZOS)
    flx  = (fvw-flsz)//2
    fly  = int(fvh*0.16)
    paste_rgba(fv_hat, fl, (flx, fly))
    fv_d = ImageDraw.Draw(fv_hat)
    fv_d.text((fvw//2, int(fvh*0.61)), "GIGHIVE",
              font=fnt(int(fvw*0.075), True), fill=C_NAVY, anchor='mm')
paste_rgba(canvas, fv_hat, (FV[0], FV[1]+30))

# ── DESIGN SUMMARY ────────────────────────────────────────────────────────────
draw.text((DS[0]+18, DS[1]+14), "DESIGN SUMMARY", font=fnt(16, True), fill=C_WHITE)
bullets = [
    "Modern GigHive bee logo on front",
    '"GigHive" script embroidery — left side',
    '"Founded 2026" embroidery — right side',
    '"GIGHIVE" arched embroidery — back',
    "Cream / natural front panel",
    "Sky-blue pigment-dyed sides & brim",
    "Two-tone unstructured cotton",
    "Adjustable buckle closure",
]
by = DS[1]+48
for b in bullets:
    draw.text((DS[0]+22, by), "•", font=fnt(15, True), fill=C_ACCENT)
    draw.text((DS[0]+36, by), b,   font=fnt(15),        fill=C_WHITE)
    by += 28

# ── Save ─────────────────────────────────────────────────────────────────────
out_rgb = canvas.convert('RGB')
out_rgb.save(OUTPUT, 'PNG', dpi=(150,150))
print(f'\n✓  Saved → {OUTPUT}')
