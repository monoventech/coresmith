<script lang="ts" module>
	import { type VariantProps, tv } from "tailwind-variants";

	export const badgeVariants = tv({
		base: "inline-flex w-fit shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md border border-transparent px-2 py-0.5 font-sans text-[12px] leading-[1.4] font-medium tracking-[0.02em]",
		variants: {
			variant: {
				default: "bg-muted text-muted-foreground",
				accent: "badge-accent",
				success: "badge-success",
				warning: "badge-warning",
				destructive: "badge-destructive",
				outline: "border-border bg-transparent text-foreground",
			},
		},
		defaultVariants: {
			variant: "default",
		},
	});

	export type BadgeVariant = VariantProps<typeof badgeVariants>["variant"];

	export const badgeDots: Record<string, string> = {
		accent: "bg-primary",
		success: "bg-success",
		warning: "bg-warning",
		destructive: "bg-destructive",
	};
</script>

<script lang="ts">
	import type { HTMLAttributes } from "svelte/elements";
	import { cn, type WithElementRef } from "@/lib/utils.js";

	let {
		ref = $bindable(null),
		class: className,
		variant = "default",
		dot = false,
		pulse = false,
		children,
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLSpanElement>> & {
		variant?: BadgeVariant;
		dot?: boolean;
		pulse?: boolean;
	} = $props();
</script>

<span bind:this={ref} data-slot="badge" class={cn(badgeVariants({ variant }), className)} {...restProps}>
	{#if dot}
		<span
			class={cn("size-1.5 shrink-0 rounded-full", badgeDots[variant ?? "default"] ?? "bg-muted-foreground", pulse && "status-dot-pulse")}
			aria-hidden="true"
		></span>
	{/if}
	{@render children?.()}
</span>
