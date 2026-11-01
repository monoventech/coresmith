// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type TrackedPointer = {
    enter: (event: PointerEvent) => void
    moved: (event: PointerEvent) => boolean
}

export function trackedPointer(): TrackedPointer {
    let last: [number, number] = [-1, -1]

    return {
        enter(event) {
            last = [event.screenX, event.screenY]
        },
        moved(event) {
            if (event.pointerType === 'touch' || (event.screenX === last[0] && event.screenY === last[1])) {
                return false
            }

            last = [event.screenX, event.screenY]

            return true
        }
    }
}
