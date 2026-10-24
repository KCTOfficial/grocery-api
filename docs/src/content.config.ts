import { defineCollection } from 'astro:content';
import { docsLoader } from '@astrojs/starlight/loaders';
import { docsSchema } from '@astrojs/starlight/schema';

// Every Markdown file in src/content/docs is a documentation page.
export const collections = {
	docs: defineCollection({ loader: docsLoader(), schema: docsSchema() }),
};
