<script lang="ts">
	import { Switch as SwitchPrimitive } from "bits-ui";
	import { cn, type WithoutChildrenOrChild } from "@/lib/utils.js";

	let {
		ref = $bindable(null),
		class: className,
		checked = $bindable(false),
		busy = false,
		...restProps
	}: WithoutChildrenOrChild<SwitchPrimitive.RootProps> & {
		busy?: boolean;
	} = $props();
</script>

<SwitchPrimitive.Root
	bind:ref
	bind:checked
	data-slot="switch"
	aria-busy={busy || undefined}
	class={cn(
		"focus-ring relative inline-block h-6 w-10 shrink-0 cursor-pointer rounded-md border border-border p-0 transition-colors duration-(--dur-fast) ease-(--ease-out-soft) data-[state=checked]:bg-primary data-[state=unchecked]:bg-muted data-disabled:cursor-not-allowed data-disabled:opacity-50",
		busy && "data-[state=checked]:bg-track-busy data-[state=unchecked]:bg-track-busy",
		className
	)}
	{...restProps}
>
	<SwitchPrimitive.Thumb
		data-slot="switch-thumb"
		class={cn(
			"pointer-events-none absolute top-0.5 block size-4.5 rounded-[6px] transition-[left] duration-(--dur-fast) ease-(--ease-out-soft) data-[state=checked]:left-4.5 data-[state=checked]:bg-primary-foreground data-[state=unchecked]:left-0.5 data-[state=unchecked]:bg-muted-foreground",
			busy && "data-[state=checked]:left-2.5 data-[state=unchecked]:left-2.5"
		)}
	/>
</SwitchPrimitive.Root>
