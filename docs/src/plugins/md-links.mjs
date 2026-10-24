import { fileURLToPath } from 'node:url';

/**
 * Sätteri (Astro's Markdown processor) plugin that rewrites links between documentation pages
 * written as Markdown files ("rest-api.md", "./relations.md#embedding-syntax") into website routes
 * ("../rest-api/", "../relations/#embedding-syntax").
 *
 * The Markdown sources therefore keep working when browsed on GitHub, while the generated site
 * gets clean URLs. Routes are relative, so any `base` path (GitHub Pages project site) works.
 */
const MD_LINK = /^(?:\.\/)?([A-Za-z0-9_-]+)\.md(#[^\s]*)?$/;

export default function mdLinks() {
	const rewrite = (node, ctx) => {
		const match = typeof node.url === 'string' ? node.url.match(MD_LINK) : null;
		if (!match) return;

		const source = ctx.fileURL ? fileURLToPath(ctx.fileURL) : '';
		// Pages are served as "<slug>/index.html"; the home page (index.md) sits at the root.
		const prefix = /[\\/]index\.md$/.test(source) ? './' : '../';
		const name = match[1];
		const slug = name === 'index' || name === 'README' ? '' : `${name.toLowerCase()}/`;

		ctx.setProperty(node, 'url', `${prefix}${slug}${match[2] || ''}`);
	};

	return { name: 'grocery-restable-md-links', link: rewrite, definition: rewrite };
}
