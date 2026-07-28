import inertia from '@inertiajs/vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'
import tailwindcss from '@tailwindcss/vite'
import laravel from 'laravel-vite-plugin'
import { bunny } from 'laravel-vite-plugin/fonts'
import path from 'path'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Hanken Grotesk', {
                    weights: [400, 500, 600]
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 500]
                }),
                bunny('Zilla Slab', {
                    weights: [500, 600]
                })
            ]
        }),
        inertia(),
        tailwindcss(),
        svelte()
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js')
        }
    }
})
