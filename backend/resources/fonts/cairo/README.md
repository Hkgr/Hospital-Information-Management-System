# Cairo for server-side reports

Unmodified static TrueType files from the Google Fonts repository, pinned to commit `e77afb398e8fcccc0d4531600b1bd8a201bbb232` (the last revision before these static files were replaced upstream). These are independent of the variable Cairo font used by the frontend.

| File | Source | SHA-256 |
| --- | --- | --- |
| Cairo-Regular.ttf | [Google Fonts](https://raw.githubusercontent.com/google/fonts/e77afb398e8fcccc0d4531600b1bd8a201bbb232/ofl/cairo/Cairo-Regular.ttf) | `0c9a1ff13c99af2225c665c15ce8f8628617aaebbf49a571442582e0ed4ea403` |
| Cairo-Bold.ttf | [Google Fonts](https://raw.githubusercontent.com/google/fonts/e77afb398e8fcccc0d4531600b1bd8a201bbb232/ofl/cairo/Cairo-Bold.ttf) | `1936f28abe143ff104b2320157195fbc333bbdc52acf927c1353dbaeaeee7c16` |
| OFL.txt | [SIL Open Font License 1.1](https://raw.githubusercontent.com/google/fonts/e77afb398e8fcccc0d4531600b1bd8a201bbb232/ofl/cairo/OFL.txt) | `8c4b391178b8fee93759742024a8c126f09aeed345899ab06fd6a71f65d2fc75` |

The license text is unchanged; its line endings and one trailing space were normalized for Git. Its hash above describes the checked-in file. Font binaries are unmodified.

Ship all three files with `backend/resources`, along with the local report logo and ornament. mPDF registers only Cairo, enables OpenType Arabic shaping, and embeds font subsets in PDF. XLSX sets the font name **Cairo**; it does not embed font files. Install Cairo on the computer opening or printing Excel files.
