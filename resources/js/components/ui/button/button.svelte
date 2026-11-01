<script lang="ts" module>
	import { cn, type WithElementRef } from "@/lib/utils.js";
	import type { HTMLAnchorAttributes, HTMLButtonAttributes } from "svelte/elements";
	import { type VariantProps, tv } from "tailwind-variants";

	export const buttonVariants = tv({
		base: "focus-ring inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-transparent font-sans text-[14px] leading-none font-medium no-underline select-none transition-[color,background-color,border-color,transform] duration-(--dur-fast) ease-(--ease-out-soft) active:not-disabled:translate-y-px disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 cursor-pointer",
		variants: {
			variant: {
				default: "bg-primary text-primary-foreground hover:not-disabled:bg-primary-hover",
				secondary: "bg-secondary text-secondary-foreground hover:not-disabled:bg-secondary-hover",
				outline: "border-border bg-transparent text-foreground hover:not-disabled:bg-muted",
				ghost: "bg-transparent text-foreground hover:not-disabled:bg-accent",
				destructive: "bg-destructive text-destructive-foreground hover:not-disabled:bg-destructive-hover",
				link: "h-auto! bg-transparent p-0! text-primary-strong underline-offset-4 hover:underline",
			},
			size: {
				default: "h-10 px-4",
				xs: "h-8 px-2.5 text-[12px]",
				sm: "h-9 px-3",
				lg: "h-11 px-6",
				icon: "size-10",
				"icon-xs": "size-8",
				"icon-sm": "size-9",
				"icon-lg": "size-11",
			},
		},
		defaultVariants: {
			variant: "default",
			size: "default",
		},
	});

	export type ButtonVariant = VariantProps<typeof buttonVariants>["variant"];
	export type ButtonSize = VariantProps<typeof buttonVariants>["size"];

	export type ButtonProps = WithElementRef<HTMLButtonAttributes> &
		WithElementRef<HTMLAnchorAttributes> & {
			variant?: ButtonVariant;
			size?: ButtonSize;
		};
</script>

<script lang="ts">
	import { Link } from "@inertiajs/svelte";

	let {
		class: className,
		variant = "default",
		size = "default",
		ref = $bindable(null),
		href = undefined,
		type = "button",
		disabled,
		children,
		...restProps
	}: ButtonProps = $props();

	const internal = $derived(
		typeof href === "string" && !restProps.target && !restProps.download && (href.startsWith("/") || (typeof window !== "undefined" && href.startsWith(window.location.origin)))
	);

	const linkProps = $derived(restProps as Record<string, unknown>);
</script>

{#if href && internal && !disabled}
	<Link
		data-slot="button"
		class={cn(buttonVariants({ variant, size }), className)}
		{href}
		{...linkProps}
	>
		{@render children?.()}
	</Link>
{:else if href}
	<a
		bind:this={ref}
		data-slot="button"
		class={cn(buttonVariants({ variant, size }), className)}
		href={disabled ? undefined : href}
		aria-disabled={disabled}
		role={disabled ? "link" : undefined}
		tabindex={disabled ? -1 : undefined}
		{...restProps}
	>
		{@render children?.()}
	</a>
{:else}
	<button
		bind:this={ref}
		data-slot="button"
		class={cn(buttonVariants({ variant, size }), className)}
		{type}
		{disabled}
		{...restProps}
	>
		{@render children?.()}
	</button>
{/if}
