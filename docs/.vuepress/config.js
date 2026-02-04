import { defaultTheme } from '@vuepress/theme-default'
import { defineUserConfig } from 'vuepress/cli'
import { viteBundler } from '@vuepress/bundler-vite'
import { mdEnhancePlugin } from "vuepress-plugin-md-enhance"
import { redirectPlugin } from '@vuepress/plugin-redirect'
import { docsearchPlugin } from '@vuepress/plugin-docsearch'
import { sidebarConfig } from './sidebar.js'
import { removeHtmlExtensionPlugin } from 'vuepress-plugin-remove-html-extension'
import { seoPlugin } from '@vuepress/plugin-seo'

export default defineUserConfig({
  lang: 'en-US',
  title: 'Hypervel',
  description: 'A Coroutine PHP Framework for Laravel Artisans.',

  ignoreDeadLinks: true,
  bundler: viteBundler(),

  plugins: [
    seoPlugin({
      hostname: 'https://hypervel.org',
      fallBackImage: 'https://hypervel.org/home.png',
      customHead: (head) => {
        head.push(['title', {}, 'Hypervel - A Coroutine PHP Framework for Laravel Artisans.']);
      },
      ogp: (ogp, page) => ({
        ...ogp,
        'og:title': 'Hypervel - A Coroutine PHP Framework for Laravel Artisans',
        'og:description': "Hypervel is a Laravel-style framework with native coroutine support for ultra-high performance.",
        'twitter:title': 'Hypervel - A Coroutine PHP Framework For Laravel Artisans',
        'twitter:description': "Hypervel is a Laravel-style framework with native coroutine support for ultra-high performance.",
        'twitter:image': 'https://hypervel.org/home.png',
        'twitter:card': 'summary_large_image',
      }),
    }),
    removeHtmlExtensionPlugin(),
    mdEnhancePlugin({
      hint: true,
      tasklist: true,
      include: true,
      tabs: true,
      align: true,
      chart: true,
    }),
    redirectPlugin({
        config: {
          '/docs': '/docs/introduction.html',
        },
    }),
    docsearchPlugin({
      appId: 'VH8TNYFP5F',
      apiKey: '434aa2025192c8d2fb19499cb78e5d48',
      indexName: 'hypervel'
    }),
  ],

  theme: defaultTheme({
    logo: 'icon.svg',

    docsRepo: 'hypervel/hypervel.org',

    docsBranch: 'main/docs',

    navbar: [
      '/',
      {
        text: 'Documentation',
        link: '/docs/introduction',
      },
      {
        text: 'GitHub',
        link: 'https://github.com/hypervel/hypervel',
      }
    ],

    sidebar: sidebarConfig,

    sidebarDepth: 0,
  }),
})
