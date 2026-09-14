<script lang="ts">
    import FieldError from '@/components/FieldError.svelte'

    let { message = '' }: { message?: string; class?: string } = $props()
</script>

<FieldError {message} />
