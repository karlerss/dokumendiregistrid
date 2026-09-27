#!/usr/bin/env python3
"""
True redaction of strings in a PDF with PyMuPDF.

    redact_pdf.py <input.pdf> <output.pdf> <forms.json>

forms.json is a JSON array of strings. Every occurrence of every string on
every page is covered with a redaction annotation and the annotations are
applied, which removes the underlying text (and blanks image pixels under
the rectangle). Document metadata is cleared. Prints JSON to stdout:

    {"hits": {"<form>": <count>, ...}, "pages": <n>}

Exit code 0 on success, 1 on any error (message on stderr). Requires
`pip install pymupdf`.
"""
import json
import sys


def main() -> int:
    if len(sys.argv) != 4:
        sys.stderr.write(__doc__)
        return 1
    src, dst, forms_path = sys.argv[1:4]

    try:
        import fitz  # PyMuPDF
    except ImportError:
        sys.stderr.write("PyMuPDF is not installed (pip install pymupdf)\n")
        return 1

    with open(forms_path, encoding="utf-8") as fh:
        forms = [f for f in json.load(fh) if isinstance(f, str) and f.strip()]

    try:
        doc = fitz.open(src)
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(f"cannot open PDF: {exc}\n")
        return 1

    if doc.is_encrypted:
        sys.stderr.write("PDF is encrypted\n")
        return 1

    hits = {f: 0 for f in forms}
    for page in doc:
        any_on_page = False
        for form in forms:
            # Whole-word-ish matching: PyMuPDF search is substring based and
            # case-insensitive by default; keep case-sensitivity off so that
            # capitalised headings still match, and rely on the caller's
            # boundary-checked text-layer verification for precision.
            rects = page.search_for(form, quads=False)
            for rect in rects:
                page.add_redact_annot(rect, fill=(0, 0, 0))
                hits[form] += 1
                any_on_page = True
        if any_on_page:
            page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_PIXELS)

    doc.set_metadata({})
    try:
        doc.del_xml_metadata()
    except Exception:  # noqa: BLE001
        pass
    doc.scrub(attached_files=True, embedded_files=True, hidden_text=True, javascript=True, metadata=True, redactions=False, reset_fields=True, reset_responses=True, thumbnails=True, xml_metadata=True)
    doc.save(dst, garbage=4, deflate=True, clean=True)
    print(json.dumps({"hits": hits, "pages": doc.page_count}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
