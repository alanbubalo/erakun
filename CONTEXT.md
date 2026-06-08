# Domain context

Vocabulary and invariants that the code names but doesn't fully explain on its own.
See `docs/OVERVIEW.md` for the broader domain (mock Croatian Fiscalization 2.0 information intermediary).

## UBL document rendering

**UblDocumentRenderer** (`app/Actions/UblDocumentRenderer.php`) is the single owner of
"how an Invoice becomes UBL". It renders an `Invoice` into its UBL document in two forms:

- `draft(Invoice): string` — unsigned, unvalidated UBL, for on-the-fly preview.
- `signed(Invoice): string` — the signed, validated final document, returned as the
  exact bytes that were signed. Throws `InvoiceValidationException` on XSD/schematron failure.

The signed path chains generate → sign → serialise → validate. It does **not** persist:
it returns bytes and the caller (`TransitionInvoiceStatus`) stores them via `StoreInvoiceUbl`.

### Byte-fidelity contract (internal to the renderer)

The XML-DSig signature is computed over the in-memory DOM, so the bytes `signed()` returns
are the verbatim serialisation of the signed document. **They must be persisted unchanged.**
Re-serialising or pretty-printing them anywhere downstream injects whitespace the digests
never saw and breaks verification silently. This was previously a social contract spread
across `InvoiceSigner` (`formatOutput = false`) and `StoreInvoiceUbl` (store verbatim); it is
now an implementation detail behind one seam. See `memory/xmlseclibs-whitespace-trap.md`.
