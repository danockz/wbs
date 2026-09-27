#!/usr/bin/env python3
"""Minimal, dependency-light Markdown -> PDF renderer for the WBS docs.

Supports the subset used by the guides: h1-h4, paragraphs, bold (**x**),
inline code (`x`), bullet/numbered lists (incl. nesting), blockquotes,
horizontal rules, and GitHub-style pipe tables. Uses fpdf2 + DejaVuSans for
full Unicode coverage (arrows, stars, en/em dashes).
"""
import re
import sys
from fpdf import FPDF

FONT_DIR = "/usr/local/lib/python3.13/site-packages/cv2/qt/fonts"
INK = (33, 37, 41)
MUTED = (90, 98, 110)
BRAND = (23, 78, 166)
BRAND_DK = (16, 52, 112)
RULE = (210, 216, 224)
CODE_BG = (240, 242, 245)
QUOTE_BG = (238, 244, 252)
QUOTE_BAR = (23, 78, 166)
TH_BG = (23, 78, 166)
TH_TX = (255, 255, 255)
ROW_ALT = (244, 247, 251)


class PDF(FPDF):
    def header(self):
        if getattr(self, "_titlepage", False):
            return
        self.set_font("DejaVu", "", 8)
        self.set_text_color(*MUTED)
        self.set_y(8)
        self.cell(0, 5, "Win-Build-Send Platform - User Documentation", align="L")
        self.cell(0, 5, self._section or "", align="R")
        self.set_draw_color(*RULE)
        self.set_line_width(0.2)
        self.line(self.l_margin, 15, self.w - self.r_margin, 15)
        self.set_y(20)

    def footer(self):
        if getattr(self, "_titlepage", False):
            return
        self.set_y(-12)
        self.set_font("DejaVu", "", 8)
        self.set_text_color(*MUTED)
        self.set_draw_color(*RULE)
        self.line(self.l_margin, self.h - 14, self.w - self.r_margin, self.h - 14)
        self.cell(0, 6, f"Page {self.page_no()}", align="C")


def make_pdf():
    pdf = PDF(format="A4")
    pdf._section = ""
    pdf._titlepage = False
    pdf.set_auto_page_break(auto=True, margin=18)
    pdf.set_margins(18, 20, 18)
    pdf.add_font("DejaVu", "", f"{FONT_DIR}/DejaVuSans.ttf")
    pdf.add_font("DejaVu", "B", f"{FONT_DIR}/DejaVuSans-Bold.ttf")
    pdf.add_font("DejaVu", "I", f"{FONT_DIR}/DejaVuSans-Oblique.ttf")
    pdf.add_font("DejaVu", "BI", f"{FONT_DIR}/DejaVuSans-BoldOblique.ttf")
    return pdf


# ---- inline formatting: split into (text, style) runs -----------------------
INLINE_RE = re.compile(r"(\*\*.+?\*\*|`.+?`|\*.+?\*)")


def parse_inline(text):
    # normalize links [txt](url) -> txt (url)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r"\1 (\2)", text)
    runs = []
    for part in INLINE_RE.split(text):
        if not part:
            continue
        if part.startswith("**") and part.endswith("**"):
            runs.append((part[2:-2], "B", None))
        elif part.startswith("`") and part.endswith("`"):
            runs.append((part[1:-1], "CODE", None))
        elif part.startswith("*") and part.endswith("*") and len(part) > 2:
            runs.append((part[1:-1], "I", None))
        else:
            runs.append((part, "", None))
    return runs


def write_runs(pdf, runs, size=10.5, lh=5.4, color=INK, x_start=None):
    """Flow styled runs with word wrapping and a hanging indent at x_start.

    Builds a flat token stream (word / space, each with its font style), then
    lays it out left-to-right, breaking to a new line (indented to x_start)
    whenever the next word would cross the right margin. Leading spaces at the
    start of a wrapped line are dropped.
    """
    if x_start is None:
        x_start = pdf.l_margin
    right = pdf.w - pdf.r_margin

    # 1. Tokenize into (word, style, is_space, width).
    tokens = []
    for text, style, _ in runs:
        is_code = style == "CODE"
        fsize = size - (0.5 if is_code else 0)
        pdf.set_font("DejaVu", "" if is_code else style, fsize)
        for piece in re.split(r"(\s+)", text):
            if piece == "":
                continue
            is_space = piece.strip() == ""
            w = pdf.get_string_width(" " if is_space else piece)
            tokens.append([piece, style, is_space, w, fsize])

    # 2. Lay out.
    if pdf.get_y() > pdf.h - lh - 18:
        pdf.add_page()
    x = x_start
    pdf.set_x(x_start)
    line_has_content = False
    for piece, style, is_space, w, fsize in tokens:
        if is_space:
            if not line_has_content:
                continue  # drop leading space on a fresh line
            if x + w > right:
                pdf.ln(lh)
                if pdf.get_y() > pdf.h - lh - 18:
                    pdf.add_page()
                pdf.set_x(x_start)
                x = x_start
                line_has_content = False
            else:
                x += w
            continue
        # a real word
        if x + w > right and line_has_content:
            pdf.ln(lh)
            if pdf.get_y() > pdf.h - lh - 18:
                pdf.add_page()
            pdf.set_x(x_start)
            x = x_start
            line_has_content = False
        is_code = style == "CODE"
        pdf.set_font("DejaVu", "" if is_code else style, fsize)
        pdf.set_x(x)
        if is_code:
            pdf.set_fill_color(*CODE_BG)
            pdf.set_text_color(*BRAND_DK)
            pdf.cell(w, lh, piece, fill=True)
            pdf.set_text_color(*color)
        else:
            pdf.set_text_color(*color)
            pdf.cell(w, lh, piece)
        x += w
        line_has_content = True
    pdf.ln(lh)


# ---- table rendering --------------------------------------------------------
def render_table(pdf, header, rows):
    right = pdf.w - pdf.r_margin
    avail = right - pdf.l_margin
    ncol = len(header)
    # weight columns by max content length
    widths = []
    for c in range(ncol):
        cells = [header[c]] + [r[c] if c < len(r) else "" for r in rows]
        widths.append(max(6, max(len(x) for x in cells)))
    tot = sum(widths)
    widths = [max(avail * 0.12, avail * w / tot) for w in widths]
    # rescale to fit
    scale = avail / sum(widths)
    widths = [w * scale for w in widths]
    line_h = 5.0

    def cell_lines(txt, w, size):
        pdf.set_font("DejaVu", "", size)
        words = txt.split()
        lines, cur = [], ""
        for wd in words:
            t = (cur + " " + wd).strip()
            if pdf.get_string_width(t) <= w - 3:
                cur = t
            else:
                if cur:
                    lines.append(cur)
                cur = wd
        if cur:
            lines.append(cur)
        return lines or [""]

    def draw_row(cells, is_header, alt):
        runs_per = [parse_inline(c) for c in cells]
        plain = [re.sub(r"[*`]", "", c) for c in cells]
        size = 9 if is_header else 8.8
        wrapped = [cell_lines(plain[c], widths[c], size) for c in range(ncol)]
        h = line_h * max(len(w) for w in wrapped) + 2
        if pdf.get_y() + h > pdf.h - 18:
            pdf.add_page()
        x0 = pdf.l_margin
        y0 = pdf.get_y()
        # backgrounds
        if is_header:
            pdf.set_fill_color(*TH_BG)
        elif alt:
            pdf.set_fill_color(*ROW_ALT)
        else:
            pdf.set_fill_color(255, 255, 255)
        pdf.rect(x0, y0, sum(widths), h, style="F")
        cx = x0
        for c in range(ncol):
            pdf.set_xy(cx, y0 + 1)
            for li, line in enumerate(wrapped[c]):
                pdf.set_x(cx + 1.5)
                if is_header:
                    pdf.set_font("DejaVu", "B", size)
                    pdf.set_text_color(*TH_TX)
                    pdf.cell(widths[c] - 3, line_h, line)
                else:
                    # render with inline styles only if single line; else plain
                    pdf.set_font("DejaVu", "", size)
                    pdf.set_text_color(*INK)
                    # inline code / bold within table cell (simple)
                    seg = line
                    pdf.cell(widths[c] - 3, line_h, seg)
                pdf.set_xy(cx, pdf.get_y() + line_h)
            cx += widths[c]
        # borders
        pdf.set_draw_color(*RULE)
        pdf.set_line_width(0.15)
        pdf.rect(x0, y0, sum(widths), h)
        cx = x0
        for c in range(ncol - 1):
            cx += widths[c]
            pdf.line(cx, y0, cx, y0 + h)
        pdf.set_xy(x0, y0 + h)

    draw_row(header, True, False)
    for i, r in enumerate(rows):
        rr = list(r) + [""] * (ncol - len(r))
        draw_row(rr, False, i % 2 == 1)
    pdf.ln(3)


# ---- block parsing ----------------------------------------------------------
def render_markdown(pdf, md, section_name, start_new_page_on_h2=False):
    pdf._section = section_name
    lines = md.split("\n")
    i = 0
    while i < len(lines):
        line = lines[i]
        raw = line.rstrip()
        stripped = raw.strip()

        # blank
        if stripped == "":
            i += 1
            continue

        # horizontal rule
        if re.fullmatch(r"-{3,}", stripped):
            pdf.ln(1.5)
            pdf.set_draw_color(*RULE)
            pdf.set_line_width(0.3)
            pdf.line(pdf.l_margin, pdf.get_y(), pdf.w - pdf.r_margin, pdf.get_y())
            pdf.ln(3)
            i += 1
            continue

        # table
        if "|" in raw and i + 1 < len(lines) and re.search(r"\|?\s*:?-{2,}", lines[i + 1]):
            header = [c.strip() for c in stripped.strip("|").split("|")]
            i += 2
            rows = []
            while i < len(lines) and "|" in lines[i] and lines[i].strip():
                rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")])
                i += 1
            render_table(pdf, header, rows)
            continue

        # headings
        m = re.match(r"(#{1,4})\s+(.*)", stripped)
        if m:
            level = len(m.group(1))
            text = re.sub(r"[*`]", "", m.group(2))
            if level == 1:
                if pdf.page_no() > 0:
                    pdf.add_page()
                pdf.set_font("DejaVu", "B", 20)
                pdf.set_text_color(*BRAND_DK)
                pdf.multi_cell(0, 9, text)
                pdf.set_draw_color(*BRAND)
                pdf.set_line_width(0.6)
                pdf.line(pdf.l_margin, pdf.get_y() + 1, pdf.w - pdf.r_margin, pdf.get_y() + 1)
                pdf.ln(5)
            elif level == 2:
                if start_new_page_on_h2:
                    pdf.add_page()
                else:
                    pdf.ln(2)
                    if pdf.get_y() > pdf.h - 45:
                        pdf.add_page()
                pdf.set_font("DejaVu", "B", 15)
                pdf.set_text_color(*BRAND)
                pdf.multi_cell(0, 7.5, text)
                pdf.ln(2)
            elif level == 3:
                pdf.ln(1.5)
                if pdf.get_y() > pdf.h - 40:
                    pdf.add_page()
                pdf.set_font("DejaVu", "B", 12)
                pdf.set_text_color(*INK)
                pdf.multi_cell(0, 6.5, text)
                pdf.ln(1)
            else:
                pdf.set_font("DejaVu", "B", 10.5)
                pdf.set_text_color(*MUTED)
                pdf.multi_cell(0, 6, text.upper())
                pdf.ln(0.5)
            i += 1
            continue

        # blockquote (possibly multi-line)
        if stripped.startswith(">"):
            qlines = []
            while i < len(lines) and lines[i].strip().startswith(">"):
                qlines.append(re.sub(r"^\s*>\s?", "", lines[i]))
                i += 1
            qtext = " ".join(x.strip() for x in qlines if x.strip())
            y0 = pdf.get_y()
            pdf.set_left_margin(pdf.l_margin + 5)
            pdf.set_x(pdf.l_margin + 5)
            start_y = pdf.get_y()
            write_runs(pdf, parse_inline(qtext), size=10, lh=5.2, color=(60, 66, 76),
                       x_start=pdf.l_margin + 5)
            end_y = pdf.get_y()
            pdf.set_draw_color(*QUOTE_BAR)
            pdf.set_line_width(1.2)
            pdf.line(pdf.l_margin + 1.5, start_y, pdf.l_margin + 1.5, end_y - 1)
            pdf.set_left_margin(pdf.l_margin - 0 if False else 18)
            pdf.set_margins(18, 20, 18)
            pdf.ln(2)
            continue

        # lists
        lm = re.match(r"^(\s*)([-*]|\d+\.)\s+(.*)", raw)
        if lm:
            while i < len(lines):
                lm = re.match(r"^(\s*)([-*]|\d+\.)\s+(.*)", lines[i].rstrip("\n"))
                if not lm:
                    if lines[i].strip() == "":
                        # peek: continue list only if next non-blank is a list item
                        j = i + 1
                        while j < len(lines) and lines[j].strip() == "":
                            j += 1
                        if j < len(lines) and re.match(r"^(\s*)([-*]|\d+\.)\s+", lines[j]):
                            i += 1
                            continue
                    break
                indent = len(lm.group(1))
                marker = lm.group(2)
                content = lm.group(3)
                depth = 0 if indent < 2 else 1
                ordered = bool(re.match(r"\d+\.", marker))
                bullet = f"{marker} " if ordered else "•  "
                x_bullet = pdf.l_margin + 3 + depth * 6
                x_text = x_bullet + (7 if ordered else 6)
                if pdf.get_y() > pdf.h - 24:
                    pdf.add_page()
                pdf.set_xy(x_bullet, pdf.get_y())
                pdf.set_font("DejaVu", "B" if not ordered else "", 10.5)
                pdf.set_text_color(*BRAND if not ordered else INK)
                pdf.cell(x_text - x_bullet, 5.4, bullet)
                write_runs(pdf, parse_inline(content), size=10.5, lh=5.4,
                           x_start=x_text)
                i += 1
            pdf.ln(1.5)
            continue

        # paragraph (gather until blank/blockish)
        para = [stripped]
        i += 1
        while i < len(lines):
            nxt = lines[i].strip()
            if nxt == "" or re.match(r"(#{1,4})\s", nxt) or nxt.startswith(">") \
               or re.match(r"^(\s*)([-*]|\d+\.)\s+", lines[i]) \
               or re.fullmatch(r"-{3,}", nxt) or ("|" in nxt and nxt.startswith("|")):
                break
            para.append(nxt)
            i += 1
        write_runs(pdf, parse_inline(" ".join(para)), size=10.5, lh=5.6)
        pdf.ln(1.6)


def title_page(pdf):
    pdf._titlepage = True
    pdf.add_page()
    pdf.set_fill_color(*BRAND_DK)
    pdf.rect(0, 0, pdf.w, 85, style="F")
    pdf.set_fill_color(*BRAND)
    pdf.rect(0, 85, pdf.w, 6, style="F")
    pdf.set_xy(18, 30)
    pdf.set_font("DejaVu", "B", 30)
    pdf.set_text_color(255, 255, 255)
    pdf.multi_cell(0, 13, "Win-Build-Send Platform")
    pdf.set_x(18)
    pdf.set_font("DejaVu", "", 16)
    pdf.set_text_color(210, 224, 245)
    pdf.multi_cell(0, 9, "User Documentation & Role Quick-Start Guide")

    pdf.set_xy(18, 110)
    pdf.set_font("DejaVu", "", 11.5)
    pdf.set_text_color(*INK)
    pdf.multi_cell(0, 6.5,
                   "A printable handbook for every user level. It opens with a one-page "
                   "quick-start card for each role, followed by the complete user guide "
                   "covering every task the platform supports.")
    pdf.ln(4)
    pdf.set_x(18)
    pdf.set_font("DejaVu", "B", 12)
    pdf.set_text_color(*BRAND)
    pdf.multi_cell(0, 7, "Inside this document")
    items = [
        "Part I  -  Role Quick-Start Cards (one page per role)",
        "Part II -  Complete User Guide (task-by-task, by role/level)",
        "Appendices - Permission reference & common questions",
    ]
    pdf.set_font("DejaVu", "", 11)
    pdf.set_text_color(*INK)
    for it in items:
        pdf.set_x(22)
        pdf.multi_cell(0, 6.5, "•  " + it)
    pdf.ln(6)
    pdf.set_x(18)
    pdf.set_draw_color(*RULE)
    pdf.line(18, pdf.get_y(), pdf.w - 18, pdf.get_y())
    pdf.ln(3)
    pdf.set_x(18)
    pdf.set_font("DejaVu", "I", 9.5)
    pdf.set_text_color(*MUTED)
    pdf.multi_cell(0, 5.5, "Generated 2026-09-08  -  Access is governed by role, scope and "
                   "per-group configuration. Every sensitive action is audited and "
                   "self-approval is blocked by segregation of duties.")
    pdf._titlepage = False


def part_divider(pdf, kicker, title, blurb):
    pdf.add_page()
    pdf.ln(30)
    pdf.set_font("DejaVu", "B", 12)
    pdf.set_text_color(*BRAND)
    pdf.cell(0, 8, kicker)
    pdf.ln(11)
    pdf.set_font("DejaVu", "B", 24)
    pdf.set_text_color(*BRAND_DK)
    pdf.multi_cell(0, 12, title)
    pdf.set_draw_color(*BRAND)
    pdf.set_line_width(0.6)
    pdf.line(pdf.l_margin, pdf.get_y() + 2, pdf.w - pdf.r_margin, pdf.get_y() + 2)
    pdf.ln(8)
    pdf.set_font("DejaVu", "", 11.5)
    pdf.set_text_color(*INK)
    pdf.multi_cell(0, 6.5, blurb)


def main():
    quick = open("docs/ROLE_QUICKSTARTS.md").read()
    guide = open("docs/USER_GUIDE.md").read()
    # drop the top H1 of each (we supply dividers/title)
    quick = re.sub(r"\A#\s+.*\n", "", quick, count=1)
    guide_body = re.sub(r"\A#\s+.*\n", "", guide, count=1)

    pdf = make_pdf()
    title_page(pdf)

    part_divider(pdf, "PART I", "Role Quick-Start Cards",
                 "One page per role: the handful of tasks you'll do most often and "
                 "how to do them. Tear off your card; the full detail is in Part II.")
    render_markdown(pdf, quick, "Quick-Start Cards", start_new_page_on_h2=True)

    part_divider(pdf, "PART II", "Complete User Guide",
                 "The full reference, organised by who you are. Start with Everyone, "
                 "then jump to the section for your role.")
    render_markdown(pdf, guide_body, "User Guide", start_new_page_on_h2=False)

    out = "docs/WBS_Platform_User_Guide.pdf"
    pdf.output(out)
    print("wrote", out)


if __name__ == "__main__":
    main()
