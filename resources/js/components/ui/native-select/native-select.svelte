<script lang="ts">
	import ChevronDownIcon from "@lucide/svelte/icons/chevron-down";
	import type { HTMLSelectAttributes } from "svelte/elements";
	import { cn, type WithElementRef } from "@/lib/utils.js";

	type Option = string | { value: string; label: string; disabled?: boolean };

	let {
		ref = $bindable(null),
		value = $bindable(),
		options = [],
		placeholder,
		class: className,
		children,
		...restProps
	}: WithElementRef<HTMLSelectAttributes> & {
		options?: Option[];
		placeholder?: string;
	} = $props();

	const normalised = $derived(options.map((option) => (typeof option === "string" ? { value: option, label: option, disabled: false } : option)));
</script>

<span class="relative inline-block w-full">
	<select
		bind:this={ref}
		bind:value
		data-slot="native-select"
		class={cn(
			"focus-ring h-10 w-full cursor-pointer appearance-none rounded-md border border-input bg-background pr-9 pl-3 font-sans text-[14px] text-foreground transition-[border-color] duration-(--dur-fast) ease-(--ease-out-soft) outline-none focus-visible:border-ring aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50",
			className
		)}
		{...restProps}
	>
		{#if placeholder}
			<option value="" disabled>{placeholder}</option>
		{/if}
		{#each normalised as option (option.value)}
			<option value={option.value} disabled={option.disabled}>{option.label}</option>
		{/each}
		{@render children?.()}
	</select>
	<ChevronDownIcon class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
</span>
