# Grocery Restable documentation website

The documentation is written in Markdown and published with [Astro](https://astro.build) +
[Starlight](https://starlight.astro.build) to GitHub Pages.

```
docs/
├── src/content/docs/     ← the pages (Markdown). index.md is the landing page.
│   └── images/           ← screenshots referenced by the pages
├── src/assets/logo.svg   ← header logo
├── src/styles/custom.css ← brand colours and small tweaks
├── src/plugins/          ← Markdown link rewriting (see below)
├── public/               ← copied as-is (favicon)
└── astro.config.mjs      ← site title, sidebar, GitHub links
```

## Run locally

Requires Node.js 22.23 or newer.

```bash
cd docs
npm install
npm run dev       # http://localhost:4321 with live reload
npm run build     # static site in docs/dist
npm run preview   # serve the build
```

## Writing pages

- Every page starts with frontmatter; the title is rendered as the page heading, so don't add an `# H1`:

  ```md
  ---
  title: "Relations and embedding"
  description: "One sentence shown in search results and link previews."
  sidebar:
    label: "Relations"   # optional shorter label
  ---
  ```

- Link to other pages by file name, e.g. `[REST API reference](rest-api.md#filters)`. The links work when browsing
  the files on GitHub, and `src/plugins/md-links.mjs` turns them into site URLs (`../rest-api/#filters`).
- Put images in `src/content/docs/images/` and reference them relatively (`![Dashboard](images/dashboard.png)`);
  Astro optimizes them at build time.
- New pages must be added to the `sidebar` in `astro.config.mjs`.

## Deployment

`.github/workflows/docs.yml` builds the site on every change under `docs/` and deploys `master` to GitHub Pages
(Settings → Pages → Source: **GitHub Actions**). The site URL and base path are derived from the repository
(`https://<owner>.github.io/<repository>/`); set the `DOCS_SITE_URL` / `DOCS_BASE_PATH` repository variables to use
a custom domain.
