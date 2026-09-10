# Cairo — local application font

`Cairo-Variable.ttf` is the original Cairo variable font, covering Arabic and Latin with weights 200–1000. The application uses its normal style through `@font-face` in `src/app/globals.css` and the shared `--mbz-font` / Tailwind `font-sans` tokens.

The font is committed with the application. Both development and production builds resolve the file locally and serve it from the application's origin; there is no Google Fonts stylesheet, remote CDN URL, or build-time font download.

- Source: https://github.com/google/fonts/tree/main/ofl/cairo
- Upstream: https://github.com/Gue3bara/Cairo/tree/73d16933c6a0f341c27a69e401da83dcb0d53114
- Original filename: `Cairo[slnt,wght].ttf` (bytes unchanged).
- License: SIL Open Font License 1.1; see `OFL.txt`.

Existing MBZ/Noto font assets remain in `public/brand/fonts` as part of the earlier brand package; the application no longer imports their stylesheet. The hospital logo remains its original SVG artwork.
