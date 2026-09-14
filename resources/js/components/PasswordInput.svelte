<script lang="ts">
    import Eye from '@lucide/svelte/icons/eye'
    import EyeOff from '@lucide/svelte/icons/eye-off'
    import { Input } from '@/components/ui/input'
    import { cn } from '@/lib/utils'

    let { class: className = '', ...rest } = $props()

    let showPassword = $state(false)
</script>

<div class="relative">
    <Input type={showPassword ? 'text' : 'password'} class={cn('pr-11', className)} {...rest} />
    <button
        type="button"
        onclick={() => (showPassword = !showPassword)}
        class="focus-ring absolute inset-y-1 right-1 flex cursor-pointer items-center rounded-md bg-transparent px-2.5 text-muted-foreground transition-colors duration-(--dur-fast) hover:text-foreground"
        aria-label={showPassword ? 'Hide password' : 'Show password'}
        tabindex={-1}
    >
        {#if showPassword}
            <EyeOff class="size-4" />
        {:else}
            <Eye class="size-4" />
        {/if}
    </button>
</div>
