<script lang="ts">
	import { cn, type WithElementRef, type WithoutChildren } from "@/lib/utils.js";
	import type { HTMLTextareaAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		value = $bindable(),
		class: className,
		"data-slot": dataSlot = "textarea",
		...restProps
	}: WithoutChildren<WithElementRef<HTMLTextareaAttributes>> = $props();
</script>

<textarea
	bind:this={ref}
	data-slot={dataSlot}
	class={cn(
		"focus-ring w-full resize-y rounded-md border border-input bg-background px-3 py-2.5 font-sans text-[14px] leading-[1.5] text-foreground transition-[border-color] duration-(--dur-fast) ease-(--ease-out-soft) outline-none placeholder:text-muted-foreground focus-visible:border-ring aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50",
		className
	)}
	bind:value
	{...restProps}
></textarea>
