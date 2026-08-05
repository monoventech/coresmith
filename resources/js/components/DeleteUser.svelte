<script lang="ts">
    import { Form } from '@inertiajs/svelte'
    import Heading from '@/components/Heading.svelte'
    import InputError from '@/components/InputError.svelte'
    import PasswordInput from '@/components/PasswordInput.svelte'
    import { Button } from '@/components/ui/button'
    import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
    import { Label } from '@/components/ui/label'
</script>

<div class="space-y-6">
    <Heading variant="small" title="Delete account" description="Delete your account and everything in it" />

    <div class="space-y-4 rounded-xl border border-destructive/20 bg-destructive/5 p-5">
        <div class="space-y-1">
            <p class="font-medium text-destructive-strong">This cannot be undone</p>
            <p class="text-sm text-muted-foreground">Your servers, deployments, and account data are removed for good.</p>
        </div>

        <Dialog>
            <DialogTrigger>
                {#snippet child({ props })}
                    <Button variant="destructive" data-test="delete-user-button" {...props}>Delete account</Button>
                {/snippet}
            </DialogTrigger>
            <DialogContent>
                <Form method="delete" action={route('profile.destroy')} class="space-y-6" options={{ preserveScroll: true }}>
                    {#snippet children({ errors, processing })}
                        <div class="space-y-3">
                            <DialogTitle>Delete your account?</DialogTitle>
                            <DialogDescription>
                                Everything tied to this account is permanently deleted. Enter your password to confirm.
                            </DialogDescription>
                        </div>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">Password</Label>
                            <PasswordInput id="password" name="password" placeholder="Password" />
                            <InputError message={errors.password} />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>

                            <Button type="submit" variant="destructive" disabled={processing} data-test="confirm-delete-user-button">
                                Delete account
                            </Button>
                        </DialogFooter>
                    {/snippet}
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</div>
