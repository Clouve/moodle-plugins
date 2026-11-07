"""Draw the made-up images of Riverside College: the logo, the badge, the seal and the signature.

Needs Python with Pillow and the DejaVu fonts. The images are in this folder already, so
run this only to change one. Each stays under 40 KB.

    python3 docs/tools/assets/make-assets.py
"""
import math
import os

from PIL import Image, ImageDraw, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
NAVY = (31, 58, 95)
RIVER = (46, 125, 140)
SERIF = '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'
SERIF_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf'
SCALE = 4


def wave(draw, left, right, middle, height, width, colour):
    """A soft wave from left to right."""
    points = []
    for x in range(left, right + 1, 2):
        share = (x - left) / max(1, right - left)
        points.append((x, middle + math.sin(share * math.pi * 3) * height))
    draw.line(points, fill=colour, width=width, joint='curve')


def logo():
    """The word mark with a small river badge."""
    width, height = 520 * SCALE, 120 * SCALE
    image = Image.new('RGBA', (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    box = 96 * SCALE
    top = (height - box) // 2
    draw.ellipse((12 * SCALE, top, 12 * SCALE + box, top + box), fill=NAVY)
    for i, middle in enumerate((46, 60, 74)):
        wave(draw, 26 * SCALE, 94 * SCALE, middle * SCALE, 5 * SCALE, 5 * SCALE,
             (255, 255, 255) if i != 1 else (160, 215, 225))
    name = ImageFont.truetype(SERIF_BOLD, 46 * SCALE)
    tag = ImageFont.truetype(SERIF, 15 * SCALE)
    draw.text((124 * SCALE, 20 * SCALE), 'Riverside', font=name, fill=NAVY)
    draw.text((126 * SCALE, 78 * SCALE), 'COLLEGE  ·  a made-up school', font=tag, fill=RIVER)
    image = image.resize((width // SCALE, height // SCALE), Image.LANCZOS)
    image.save(os.path.join(HERE, 'logo.png'), optimize=True)


def mark():
    """The river badge alone, for the top bar, where the theme writes the school's name next to it."""
    size = 160 * SCALE
    image = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    draw.ellipse((6 * SCALE, 6 * SCALE, 154 * SCALE, 154 * SCALE), fill=NAVY)
    for i, middle in enumerate((54, 80, 106)):
        wave(draw, 30 * SCALE, 130 * SCALE, middle * SCALE, 8 * SCALE, 8 * SCALE, (255, 255, 255) if i != 1 else (160, 215, 225))
    image = image.resize((size // SCALE, size // SCALE), Image.LANCZOS)
    image.save(os.path.join(HERE, 'mark.png'), optimize=True)


def seal():
    """A round seal, with SAMPLE across it so that nobody takes it for a real one."""
    size = 480
    big = size * SCALE
    image = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    centre = big // 2
    for radius, width in ((232, 10), (216, 3), (150, 3)):
        r = radius * SCALE
        draw.ellipse((centre - r, centre - r, centre + r, centre + r), outline=NAVY, width=width * SCALE)
    for i, middle in enumerate((200, 240, 280)):
        wave(draw, 130 * SCALE, 350 * SCALE, middle * SCALE, 12 * SCALE, 9 * SCALE, RIVER if i == 1 else NAVY)
    font = ImageFont.truetype(SERIF_BOLD, 30 * SCALE)
    text = 'RIVERSIDE COLLEGE  ·  MADE-UP SEAL  ·  '
    radius = 183 * SCALE
    step = 360 / len(text)
    for i, letter in enumerate(text):
        angle = i * step
        glyph = Image.new('RGBA', (60 * SCALE, 60 * SCALE), (0, 0, 0, 0))
        ImageDraw.Draw(glyph).text((30 * SCALE, 30 * SCALE), letter, font=font, fill=NAVY, anchor='mm')
        turned = glyph.rotate(-angle, resample=Image.BICUBIC)
        x = centre + radius * math.sin(math.radians(angle))
        y = centre - radius * math.cos(math.radians(angle))
        image.alpha_composite(turned, (int(x - 30 * SCALE), int(y - 30 * SCALE)))
    stamp = ImageFont.truetype(SERIF_BOLD, 52 * SCALE)
    draw.text((centre, 350 * SCALE), 'SAMPLE', font=stamp, fill=(170, 40, 40, 255), anchor='mm')
    image = image.resize((size, size), Image.LANCZOS)
    image = image.quantize(colors=64, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    image.save(os.path.join(HERE, 'seal.png'), optimize=True)


def signature():
    """A loose pen line that spells nothing, with a small line of print under it."""
    width, height = 420 * SCALE, 120 * SCALE
    image = Image.new('RGBA', (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    points = []
    for step in range(0, 1400):
        t = step / 1400
        x = 24 + t * 360 + math.sin(t * 34) * 13
        y = 56 + math.sin(t * 17) * 26 * (1 - t * 0.7) + math.cos(t * 51) * 7
        points.append((x * SCALE, y * SCALE))
    draw.line(points, fill=(20, 30, 70), width=3 * SCALE, joint='curve')
    print_font = ImageFont.truetype(SERIF, 12 * SCALE)
    draw.text((26 * SCALE, 98 * SCALE), 'Made-up signature for a made-up registrar', font=print_font, fill=(90, 90, 90))
    image = image.resize((width // SCALE, height // SCALE), Image.LANCZOS)
    image.save(os.path.join(HERE, 'signature.png'), optimize=True)


if __name__ == '__main__':
    logo()
    mark()
    seal()
    signature()
