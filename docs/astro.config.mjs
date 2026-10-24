// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import { satteri } from '@astrojs/markdown-satteri';
import mdLinks from './src/plugins/md-links.mjs';

const repository = 'https://github.com/KCTOfficial/grocery-api';

// https://astro.build/config
export default defineConfig({
	// SITE_URL / BASE_PATH are set by .github/workflows/release.yml for GitHub Pages
	// (https://<owner>.github.io/<repository>/). Locally the site is served from "/".
	site: process.env.SITE_URL || 'https://kctofficial.github.io',
	base: process.env.BASE_PATH || '/',
	markdown: {
		// Links between the Markdown files (e.g. "rest-api.md#errors") become site routes, so the same
		// files read correctly on GitHub and on the website. Starlight adds its own plugins after this one.
		processor: satteri({ mdastPlugins: [mdLinks()] }),
	},
	integrations: [
		starlight({
			title: 'Grocery Restable',
			description:
				'Turn any MySQL / MariaDB, PostgreSQL or SQLite database into a secure REST API — configured like Grocery CRUD, managed from a phpMyAdmin-style GUI.',
			logo: { src: './src/assets/logo.svg' },
			favicon: '/favicon.svg',
			social: [{ icon: 'github', label: 'GitHub', href: repository }],
			editLink: { baseUrl: `${repository}/edit/master/docs/` },
			customCss: ['./src/styles/custom.css'],
			sidebar: [
				{
					label: 'Start here',
					items: ['overview', 'getting-started', 'codeigniter4', 'no-framework', 'database-connections', 'configuration', 'admin-gui'],
				},
				{
					label: 'Building the API',
					items: ['resources', 'relations', 'validation', 'callbacks-actions-rpc', 'uploads-and-storage', 'authentication'],
				},
				{
					label: 'Consuming the API',
					items: ['rest-api', 'openapi'],
				},
				{
					label: 'Operating',
					items: ['security', 'architecture', 'migrating-from-grocery-crud', 'troubleshooting'],
				},
			],
		}),
	],
});
