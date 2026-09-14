<script lang="ts">
	import type { HTMLInputAttributes, HTMLInputTypeAttribute } from "svelte/elements";
	import { cn, type WithElementRef } from "@/lib/utils.js";

	type InputType = Exclude<HTMLInputTypeAttribute, "file">;

	type Props = WithElementRef<
		Omit<HTMLInputAttributes, "type"> &
			({ type: "file"; files?: FileList } | { type?: InputType; files?: undefined })
	>;

	let {
		ref = $bindable(null),
		value = $bindable(),
		type,
		files = $bindable(),
		class: className,
		"data-slot": dataSlot = "input",
		...restProps
	}: Props = $props();
</script>

{#if type === "file"}
	<input
		bind:this={ref}
		data-slot={dataSlot}
		class={cn(
			"focus-ring h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 font-sans text-[14px] leading-[1.5] text-foreground transition-[border-color] duration-(--dur-fast) ease-(--ease-out-soft) outline-none placeholder:text-muted-foreground focus-visible:border-ring aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50 file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground",
			className
		)}
		type="file"
		bind:files
		bind:value
		{...restProps}
	/>
{:else}
	<input
		bind:this={ref}
		data-slot={dataSlot}
		class={cn(
			"focus-ring h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 font-sans text-[14px] leading-[1.5] text-foreground transition-[border-color] duration-(--dur-fast) ease-(--ease-out-soft) outline-none placeholder:text-muted-foreground focus-visible:border-ring aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50 file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground",
			className
		)}
		{type}
		bind:value
		{...restProps}
	/>
{/if}
