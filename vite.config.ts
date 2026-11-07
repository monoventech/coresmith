import inertia from '@inertiajs/vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'
import tailwindcss from '@tailwindcss/vite'
import laravel from 'laravel-vite-plugin'
import { local } from 'laravel-vite-plugin/fonts'
import path from 'path'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                local('Hanken Grotesk', {
                    optimizedFallbacks: false,
                    variants: [
                        { src: 'resources/fonts/HankenGrotesk-400.woff2', weight: 400, style: 'normal' },
                        { src: 'resources/fonts/HankenGrotesk-500.woff2', weight: 500, style: 'normal' },
                        { src: 'resources/fonts/HankenGrotesk-600.woff2', weight: 600, style: 'normal' }
                    ]
                }),
                local('JetBrains Mono', {
                    optimizedFallbacks: false,
                    variants: [
                        { src: 'resources/fonts/JetBrainsMono-400.woff2', weight: 400, style: 'normal' },
                        { src: 'resources/fonts/JetBrainsMono-500.woff2', weight: 500, style: 'normal' }
                    ]
                })
            ]
        }),
        inertia(),
        tailwindcss(),
        svelte()
    ],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, './resources/js')
        }
    }
})
