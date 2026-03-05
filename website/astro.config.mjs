import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
	site: 'https://whole-code.github.io',
	base: '/wp-intelligence',
	integrations: [
		starlight({
			title: 'WP Intelligence',
			description: 'Modular AI and site optimization toolkit for WordPress.',
			social: {
				github: 'https://github.com/whole-code/wp-intelligence',
			},
			sidebar: [
				{
					label: 'Getting Started',
					autogenerate: { directory: 'getting-started' },
				},
				{
					label: 'Features',
					autogenerate: { directory: 'features' },
				},
				{
					label: 'Reference',
					autogenerate: { directory: 'reference' },
				},
				'changelog',
			],
			customCss: ['./src/styles/custom.css'],
		}),
	],
});
