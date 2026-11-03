"""Builds the task list Excel from docs/tasks.json (same layout as the CoreCart task list).

Usage: python scripts/build-tasks.py [output.xlsx]
Default output: ../Claude outputs/Global Arms Post task list <date> v<version>.xlsx
Only the latest version is kept: older "Global Arms Post task list ... .xlsx" files in that
folder are deleted after each build (owner's rule, 2026-11-03).

Tabs: Frontend, Backend, Project (open tasks only), then Done, Pending, Not started.
Status rules: Not started = not begun; Pending = started but not finished;
Done = finished, and then it is ONLY on the Done tab.
"""
import json
import math
import sys
from pathlib import Path

from openpyxl import Workbook
from openpyxl.formatting.rule import FormulaRule
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.worksheet.datavalidation import DataValidation

ROOT = Path(__file__).resolve().parent.parent
DATA = json.loads((ROOT / "docs" / "tasks.json").read_text(encoding="utf-8"))

STATUSES = ["Done", "Pending", "Not started"]
SIDES = ["Frontend", "Backend", "Project"]
COLORS = {  # status: (badge fill, badge text, row tint)
    "Done": ("16A34A", "FFFFFF", "DCFCE7"),
    "Pending": ("EAB308", "111827", "FEF9C3"),
    "Not started": ("DC2626", "FFFFFF", "FEE2E2"),
}
WIDTHS = {"A": 7, "B": 17, "C": 60, "D": 12, "E": 12, "F": 54, "G": 36}
HEADERS = ["ID", "Area", "Task", "Side", "Status", "Why / waiting for", "Next step (who)"]
HEAD_ALIGN = ["center", "left", "left", "left", "center", "left", "left"]
FIRST = 6  # first task row

thin = Side(style="thin", color="D1D5DB")
BORDER = Border(left=thin, right=thin, top=thin, bottom=thin)


def id_key(t):
    order = {s: i for i, s in enumerate(SIDES)}
    return (order.get(t["side"], 9), t["id"][0], int("".join(c for c in t["id"] if c.isdigit()) or 0))


def row_height(values):
    lines = 1
    for col, v in zip("ABCDEFG", values):
        chars_per_line = WIDTHS[col] * 1.15
        lines = max(lines, math.ceil(len(str(v)) / chars_per_line))
    return 17 if lines == 1 else 15 * lines + 2


def build_sheet(wb, title, heading, note, tasks, tab=None, dropdown=True):
    ws = wb.create_sheet(title)
    if tab:
        ws.sheet_properties.tabColor = tab
    for col, w in WIDTHS.items():
        ws.column_dimensions[col].width = w
    last = FIRST + max(len(tasks), 1) - 1

    ws["A1"] = heading
    ws["A1"].font = Font(name="Arial", size=16, bold=True)
    ws["A2"] = note
    ws["A2"].font = Font(name="Arial", size=9, color="4B5563")

    ws["A3"] = "Count"
    ws["A3"].font = Font(name="Arial", bold=True)
    for label_col, count_col, status in (("B", "C", "Done"), ("D", "E", "Pending"), ("F", "G", "Not started")):
        fill, text, _ = COLORS[status]
        c = ws[f"{label_col}3"]
        c.value = status
        c.font = Font(name="Arial", bold=True, color=text)
        c.fill = PatternFill("solid", fgColor=fill)
        c.alignment = Alignment(horizontal="center")
        n = ws[f"{count_col}3"]
        n.value = f'=COUNTIF($E${FIRST}:$E${last},"{status}")'
        n.font = Font(name="Arial", bold=True)
        n.alignment = Alignment(horizontal="center")

    ws.row_dimensions[5].height = 24
    for i, h in enumerate(HEADERS, start=1):
        c = ws.cell(5, i, h)
        c.font = Font(name="Arial", size=11, bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor="111827")
        c.alignment = Alignment(horizontal=HEAD_ALIGN[i - 1], vertical="center", wrap_text=True)
        c.border = BORDER

    for r, t in enumerate(tasks, start=FIRST):
        values = [t["id"], t["area"], t["task"], t["side"], t["status"], t["why"], t["next"]]
        for i, v in enumerate(values, start=1):
            c = ws.cell(r, i, v)
            c.font = Font(name="Arial", size=10, bold=(i == 5))
            c.alignment = Alignment(horizontal=HEAD_ALIGN[i - 1], vertical="top", wrap_text=True)
            c.border = BORDER
        ws.row_dimensions[r].height = row_height(values)

    rng_status = f"E{FIRST}:E{last}"
    rng_row = f"A{FIRST}:G{last}"
    for status, (fill, text, tint) in COLORS.items():
        ws.conditional_formatting.add(rng_status, FormulaRule(
            formula=[f'$E{FIRST}="{status}"'], font=Font(color=text, bold=True),
            fill=PatternFill("solid", fgColor=fill, bgColor=fill)))
    for status, (fill, text, tint) in COLORS.items():
        ws.conditional_formatting.add(rng_row, FormulaRule(
            formula=[f'$E{FIRST}="{status}"'], fill=PatternFill("solid", fgColor=tint, bgColor=tint)))

    if dropdown:
        dv = DataValidation(type="list", formula1='"Done,Pending,Not started"', allow_blank=False)
        ws.add_data_validation(dv)
        dv.add(rng_status)

    ws.freeze_panes = "A6"
    ws.auto_filter.ref = f"A5:G{last}"
    ws.page_setup.orientation = "landscape"
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 0
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    ws.print_title_rows = "5:5"
    return ws


def main():
    tasks = sorted(DATA["tasks"], key=id_key)
    for t in tasks:
        if t["status"] not in STATUSES:
            raise SystemExit(f"{t['id']}: unknown status {t['status']!r}")
        if t["side"] not in SIDES:
            raise SystemExit(f"{t['id']}: unknown side {t['side']!r}")
    ids = [t["id"] for t in tasks]
    dupes = {i for i in ids if ids.count(i) > 1}
    if dupes:
        raise SystemExit(f"Duplicate IDs: {sorted(dupes)}")

    name = DATA["project"]
    open_note = (
        f"{DATA['update_note']} ID = fixed task code (F = Frontend, B = Backend, P = Project: docs, testing, deploy): "
        "tell Claude e.g. 'check F7'; it never changes. Only open tasks are on this tab: "
        "Pending = started but not finished (or waiting for something) · Not started = not begun yet. "
        "Every Done task is on the Done tab only. Change Status with the dropdown and tell Claude to rebuild."
    )
    status_note = (
        "Every task with this status, grouped Frontend, Backend, Project. Done tasks are ONLY on the Done tab "
        "(not on the other tabs). To change a status, tell Claude (it rebuilds the file)."
    )

    wb = Workbook()
    wb.remove(wb.active)
    for side, heading in (
        ("Frontend", f"{name} – Frontend tasks still open"),
        ("Backend", f"{name} – Backend tasks still open"),
        ("Project", f"{name} – Project tasks still open (docs, testing, deploy)"),
    ):
        rows = [t for t in tasks if t["side"] == side and t["status"] != "Done"]
        build_sheet(wb, side, heading, open_note, rows)
    for status in STATUSES:
        rows = [t for t in tasks if t["status"] == status]
        build_sheet(
            wb, status, f"{name} – {status} ({len(rows)} tasks, frontend + backend + project)",
            status_note, rows, tab=COLORS[status][0], dropdown=False,
        )
    wb.calculation.fullCalcOnLoad = True

    if len(sys.argv) > 1:
        out = Path(sys.argv[1])
    else:
        out = ROOT.parent / "Claude outputs" / f"{name} task list {DATA['updated']} v{DATA['version']}.xlsx"
    out.parent.mkdir(parents=True, exist_ok=True)
    wb.save(out)
    counts = {s: sum(t["status"] == s for t in tasks) for s in STATUSES}
    print(out)
    print(counts)
    # Hard rule 7 (owner, 2026-11-03): only the latest task list is kept. Older versions
    # of this task list (same name pattern, same folder) are deleted after the new one is saved.
    if len(sys.argv) == 1:
        for old in out.parent.glob(f"{name} task list * v*.xlsx"):
            if old != out:
                old.unlink()
                print("deleted old version:", old.name)


if __name__ == "__main__":
    main()
