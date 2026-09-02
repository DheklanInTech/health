# Weight Loss Trials — Next.js export

Next.js 14 (App Router) + Tailwind CSS + TypeScript recreation of the "Weight Loss Trials" page.

## Run it

```bash
npm install
npm run dev
```

Open http://localhost:3000.

## Structure

- `app/layout.tsx` — root layout, loads Archivo via `next/font/google`
- `app/page.tsx` — assembles the page from the components below
- `app/globals.css` — Tailwind directives + global `body`/`a` styles
- `components/Nav.tsx` — header nav bar
- `components/Hero.tsx` — hero banner (photo placeholder — drop in a real image)
- `components/Gallery.tsx` — 3-photo gallery row (placeholders)
- `components/ScreeningForm.tsx` — the 4-step screening questionnaire (client component, holds its own `step` state)
- `components/Footer.tsx` — footer

## Design tokens

`tailwind.config.ts` carries the palette as `accent` (green, primary) and `accent2` (blue, secondary) 100–900 scales, plus `ink`/`ink2`/`bg`/`divider` for the neutral/ink tones. All border-radius is forced to `0` to match the flat, unrounded look. Swap the hex values in `tailwind.config.ts` to retheme the whole app.

## Notes for the developer

- The hero and gallery images are plain placeholder `div`s — replace with real `<Image>` components once photography is available.
- Social links in the footer are text-monogram badges (X / FB / IN / IG) rather than icon glyphs — swap in your icon set of choice.
- The form doesn't wire up submission — add a server action or API route per your backend.
