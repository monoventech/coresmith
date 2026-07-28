<script lang="ts">
    let {
        class: className = '',
        ...rest
    }: {
        class?: string
        [key: string]: unknown
    } = $props()
</script>

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    class={className}
    {...rest}
>
    <path d="M2.9 7.9h13.4c2.4 0 4.2 1 5.2 2.5-1.6 1-3.4 1.5-5.4 1.5H2.9Z" />
    <path d="M8.9 11.9v2.6c0 1-.7 1.9-2 2.6h10.2c-1.3-.7-2-1.6-2-2.6v-2.6" />
    <path d="M5.6 17.1h12.8c.7 0 1.1.5 1.1 1.2v.8H4.5v-.8c0-.7.4-1.2 1.1-1.2Z" />
</svg>
