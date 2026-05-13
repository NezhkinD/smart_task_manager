#!/usr/bin/env python3
"""
migrate_text.py — Migrate text from indexing XML (File 1) to Word Flat OPC XML (File 2).

File 1 has the up-to-date text in a simple <indexing>/<paragraph> format.
File 2 has the up-to-date Word formatting but outdated text.

This script:
  1. Parses both files
  2. Matches paragraphs via difflib.SequenceMatcher
  3. Replaces text in File 2's <w:p> elements with text from File 1
  4. Removes pink annotation highlights (w:shd fill="BF819E")
  5. Writes the result as a valid Word Flat OPC XML

Usage:
    python migrate_text.py [--dry-run] [--verbose]
"""

import argparse
import logging
import sys
from difflib import SequenceMatcher
from pathlib import Path

from lxml import etree

log = logging.getLogger("migrate_text")

# ── Namespace constants ───────────────────────────────────────────

W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
PKG = "http://schemas.microsoft.com/office/2006/xmlPackage"
XMLS = "{http://www.w3.org/XML/1998/namespace}space"

PINK_FILL = "BF819E"


def wqn(local: str) -> str:
    """Clark-notation name in the w: namespace."""
    return f"{{{W}}}{local}"


# ── File 1 parsing (source text) ─────────────────────────────────


def parse_source(path: Path):
    """Parse the indexing XML.

    Returns:
        body_texts  – list[str]  body paragraph texts in document order
        tables      – list[dict] each {'name': str, 'texts': list[str]}
    """
    tree = etree.parse(str(path))
    root = tree.getroot()

    body_texts: list[str] = []
    objects_order: list[tuple[str, str, str]] = []  # (index, name, type)
    obj_children: dict[str, list[str]] = {}
    section_indices: set[str] = set()

    for elem in root:
        if elem.tag == "paragraph":
            raw = elem.text or ""
            pidx = elem.get("parent_index")
            if pidx is None:
                body_texts.append(raw)
            else:
                obj_children.setdefault(pidx, []).append(raw)
        elif elem.tag == "object":
            idx = elem.get("index")
            objects_order.append((idx, elem.get("name"), elem.get("object_type")))
            if elem.get("object_type") == "section":
                section_indices.add(idx)

    tables: list[dict] = []
    for idx, name, otype in objects_order:
        if otype == "table" and idx not in section_indices:
            children = obj_children.get(idx, [])
            if children:
                tables.append({"name": name, "texts": children})

    return body_texts, tables


# ── File 2 helpers (Word XML) ────────────────────────────────────


def wp_text(wp) -> str:
    """Concatenate all <w:t> text inside a <w:p>."""
    return "".join(wt.text or "" for wt in wp.iter(wqn("t")))


def find_body(tree):
    """Locate <w:body> inside the Flat OPC package."""
    root = tree.getroot()
    for part in root.iter(f"{{{PKG}}}part"):
        pname = part.get(f"{{{PKG}}}name", "")
        if "document.xml" in pname and "_rels" not in pname:
            xmldata = part.find(f"{{{PKG}}}xmlData")
            wdoc = xmldata[0]
            return wdoc.find(wqn("body"))
    raise RuntimeError("word/document.xml part not found")


def body_paras_and_tables(body):
    """Split body children into paragraph list and table list.

    Returns (paras, tables) where
        paras  = [(text, wp_element), ...]
        tables = [tbl_element, ...]
    """
    paras, tables = [], []
    for child in body:
        local = etree.QName(child).localname
        if local == "p":
            paras.append((wp_text(child), child))
        elif local == "tbl":
            tables.append(child)
    return paras, tables


def table_paras(tbl) -> list:
    """All <w:p> elements inside a table, in reading order."""
    return [(wp_text(p), p) for p in tbl.iter(wqn("p"))]


# ── Normalization ─────────────────────────────────────────────────


def norm(text: str) -> str:
    """Collapse whitespace for comparison."""
    return " ".join(text.split())


# ── Text replacement inside <w:p> ────────────────────────────────


def _shd_fill(shd) -> str:
    """Get fill value from a <w:shd>, trying w: namespace then bare."""
    return (shd.get(wqn("fill")) or shd.get("fill", "")).upper()


def _strip_pink(rpr):
    """Remove pink w:shd from a w:rPr element."""
    if rpr is None:
        return
    for shd in list(rpr.findall(wqn("shd"))):
        if _shd_fill(shd) == PINK_FILL:
            rpr.remove(shd)


def _text_runs(wp) -> list:
    """Direct-child <w:r> elements that carry <w:t> but not field chars."""
    runs = []
    for child in wp:
        if etree.QName(child).localname != "r":
            continue
        if child.find(wqn("fldChar")) is not None:
            continue
        if child.find(wqn("instrText")) is not None:
            continue
        if child.find(wqn("t")) is not None:
            runs.append(child)
    return runs


def _set_run_text(run, text: str):
    """Replace run content with *text*, creating <w:br>/<w:tab> for \\n/\\t."""
    has_lrpb = run.find(wqn("lastRenderedPageBreak")) is not None
    rpr = run.find(wqn("rPr"))

    # Remove everything except rPr
    for child in list(run):
        if etree.QName(child).localname != "rPr":
            run.remove(child)

    # Re-add lastRenderedPageBreak right after rPr
    if has_lrpb:
        lrpb = etree.SubElement(run, wqn("lastRenderedPageBreak"))
        if rpr is not None:
            rpr.addnext(lrpb)

    clean = text.strip()
    if not clean:
        wt = etree.SubElement(run, wqn("t"))
        wt.text = ""
        return

    for i, line in enumerate(clean.split("\n")):
        line = line.rstrip()
        if i > 0:
            etree.SubElement(run, wqn("br"))
        while line.startswith("\t"):
            etree.SubElement(run, wqn("tab"))
            line = line[1:]
        wt = etree.SubElement(run, wqn("t"))
        wt.text = line
        wt.set(XMLS, "preserve")


def replace_para(wp, new_text: str) -> bool:
    """Replace text in a <w:p> preserving formatting. Returns True on success."""
    runs = _text_runs(wp)
    if not runs:
        return False

    first = runs[0]

    # Remove pink highlight from first run and from paragraph-level rPr
    _strip_pink(first.find(wqn("rPr")))
    ppr = wp.find(wqn("pPr"))
    if ppr is not None:
        _strip_pink(ppr.find(wqn("rPr")))

    # Collect lastRenderedPageBreak from later runs
    extra_lrpb = any(
        r.find(wqn("lastRenderedPageBreak")) is not None for r in runs[1:]
    )

    # Set new text in first run
    _set_run_text(first, new_text)

    # Transfer lastRenderedPageBreak if needed
    if extra_lrpb and first.find(wqn("lastRenderedPageBreak")) is None:
        rpr = first.find(wqn("rPr"))
        lrpb = etree.SubElement(first, wqn("lastRenderedPageBreak"))
        if rpr is not None:
            rpr.addnext(lrpb)

    # Remove remaining text runs
    for run in runs[1:]:
        wp.remove(run)

    return True


# ── Matching engine ───────────────────────────────────────────────


def match_and_replace(
    src_texts: list[str],
    dst_pairs: list[tuple[str, object]],
    label: str = "body",
    dry_run: bool = False,
) -> dict:
    """Align src_texts to dst_pairs via SequenceMatcher, replace where needed.

    Returns stats dict {equal, replace, delete, insert}.
    """
    # Keep only non-empty entries (with their original indices)
    src = [(i, t) for i, t in enumerate(src_texts) if norm(t)]
    dst = [(i, t, el) for i, (t, el) in enumerate(dst_pairs) if norm(t)]

    src_norms = [norm(t) for _, t in src]
    dst_norms = [norm(t) for _, t, _ in dst]

    sm = SequenceMatcher(None, dst_norms, src_norms, autojunk=False)
    stats = {"equal": 0, "replace": 0, "delete": 0, "insert": 0}

    for op, d0, d1, s0, s1 in sm.get_opcodes():
        if op == "equal":
            stats["equal"] += d1 - d0

        elif op == "replace":
            pair_count = min(d1 - d0, s1 - s0)
            for k in range(pair_count):
                d_idx, d_text, d_el = dst[d0 + k]
                s_idx, s_text = src[s0 + k]
                log.info("[%s] REPLACE dst[%d] <- src[%d]", label, d_idx, s_idx)
                log.debug("  OLD: %s", norm(d_text)[:120])
                log.debug("  NEW: %s", norm(s_text)[:120])
                if not dry_run:
                    replace_para(d_el, s_text)
                stats["replace"] += 1
            # extras
            for k in range(pair_count, d1 - d0):
                d_idx, d_text, _ = dst[d0 + k]
                log.warning(
                    "[%s] DELETE (only in target): dst[%d] '%s'",
                    label, d_idx, norm(d_text)[:100],
                )
                stats["delete"] += 1
            for k in range(pair_count, s1 - s0):
                s_idx, s_text = src[s0 + k]
                log.warning(
                    "[%s] INSERT (only in source): src[%d] '%s'",
                    label, s_idx, norm(s_text)[:100],
                )
                stats["insert"] += 1

        elif op == "delete":  # present in dst only
            for k in range(d0, d1):
                d_idx, d_text, _ = dst[k]
                log.warning(
                    "[%s] DELETE (only in target): dst[%d] '%s'",
                    label, d_idx, norm(d_text)[:100],
                )
                stats["delete"] += 1

        elif op == "insert":  # present in src only
            for k in range(s0, s1):
                s_idx, s_text = src[k]
                log.warning(
                    "[%s] INSERT (only in source): src[%d] '%s'",
                    label, s_idx, norm(s_text)[:100],
                )
                stats["insert"] += 1

    return stats


# ── Global cleanup ────────────────────────────────────────────────


def remove_all_pink(tree) -> int:
    """Remove every <w:shd fill="BF819E"> in the entire document."""
    count = 0
    for shd in list(tree.iter(wqn("shd"))):
        if _shd_fill(shd) == PINK_FILL:
            shd.getparent().remove(shd)
            count += 1
    return count


# ── Output ────────────────────────────────────────────────────────


def write_output(tree, path: Path):
    """Serialize the tree with XML declaration and mso-application PI."""
    root = tree.getroot()
    root_bytes = etree.tostring(root, encoding="unicode")

    with open(str(path), "w", encoding="utf-8") as f:
        f.write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n')
        f.write('<?mso-application progid="Word.Document"?>\n')
        f.write(root_bytes)

    log.info("Written %s", path.name)


# ── Main ──────────────────────────────────────────────────────────


def main():
    ap = argparse.ArgumentParser(description="Migrate VKR text between XML files")
    ap.add_argument("--dry-run", action="store_true", help="Preview without writing")
    ap.add_argument("--verbose", "-v", action="store_true", help="Detailed output")
    ap.add_argument("--source", type=Path, default=None)
    ap.add_argument("--target", type=Path, default=None)
    ap.add_argument("--output", type=Path, default=None)
    args = ap.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(levelname)s: %(message)s",
    )

    base = Path(__file__).resolve().parent
    source = args.source or base / "Нежкин_ДС_ВКР_промежуточный_с_пометками.xml"
    target = args.target or base / "Нежкин_ДС_ВКР_промежуточный_с_пометками_2.xml"
    output = args.output or base / "Нежкин_ДС_ВКР_результат.xml"

    # ── Parse source ──
    log.info("Source: %s", source.name)
    src_body, src_tables = parse_source(source)
    log.info(
        "  %d body paragraphs (%d non-empty), %d tables",
        len(src_body),
        sum(1 for t in src_body if norm(t)),
        len(src_tables),
    )
    for i, tbl in enumerate(src_tables):
        log.info("    [%d] %s: %d cells", i, tbl["name"], len(tbl["texts"]))

    # ── Parse target ──
    log.info("Target: %s", target.name)
    target_tree = etree.parse(str(target))
    body = find_body(target_tree)
    dst_paras, dst_tables = body_paras_and_tables(body)
    log.info(
        "  %d body paragraphs (%d non-empty), %d tables",
        len(dst_paras),
        sum(1 for t, _ in dst_paras if norm(t)),
        len(dst_tables),
    )
    for i, tbl in enumerate(dst_tables):
        tp = table_paras(tbl)
        log.info(
            "    [%d]: %d paras (%d non-empty)",
            i, len(tp), sum(1 for t, _ in tp if norm(t)),
        )

    # ── Body paragraphs ──
    log.info("")
    log.info("=== Body paragraphs ===")
    body_stats = match_and_replace(src_body, dst_paras, "body", args.dry_run)
    log.info("Body: %s", body_stats)

    # ── Tables ──
    if len(src_tables) != len(dst_tables):
        log.warning(
            "Table count mismatch: source=%d, target=%d",
            len(src_tables), len(dst_tables),
        )

    total_tbl = {"equal": 0, "replace": 0, "delete": 0, "insert": 0}
    for i in range(min(len(src_tables), len(dst_tables))):
        st = src_tables[i]
        dt_paras = table_paras(dst_tables[i])
        log.info("")
        log.info("=== Table %d: %s ===", i, st["name"])
        ts = match_and_replace(st["texts"], dt_paras, f"tbl[{i}]", args.dry_run)
        log.info("Table %d: %s", i, ts)
        for k in total_tbl:
            total_tbl[k] += ts[k]

    log.info("")
    log.info("Tables total: %s", total_tbl)

    # ── Cleanup & write ──
    if not args.dry_run:
        pink = remove_all_pink(target_tree)
        log.info("Removed %d pink highlights", pink)
        write_output(target_tree, output)
    else:
        log.info("DRY RUN — no file written")


if __name__ == "__main__":
    main()
