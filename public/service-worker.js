self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-DOQGb-nG.js',
    '../build/assets/BlueprintRack-BUg5aGsW.js',
    '../build/assets/EmptyState-CtwlXQ4w.js',
    '../build/assets/InputError-H1Fzj7jB.js',
    '../build/assets/PasswordInput-Bm99R5Mj.js',
    '../build/assets/ServerStatusBadge-B4Wz9U4Z.js',
    '../build/assets/StatusBadge-Dowf52Z0.js',
    '../build/assets/TextLink-Dof4-hoD.js',
    '../build/assets/check-CaqjjcEp.js',
    '../build/assets/checkbox-MF31Xou0.js',
    '../build/assets/dialog-D-OeeQjh.js',
    '../build/assets/fonts-CUpTPDOR.css',
    '../build/assets/hanken-grotesk-400-normal-BG6hkEXj.woff2',
    '../build/assets/hanken-grotesk-400-normal-BLTEyOai.woff2',
    '../build/assets/hanken-grotesk-400-normal-BLrFBAHj.woff2',
    '../build/assets/hanken-grotesk-400-normal-C-iWyKLC.woff',
    '../build/assets/hanken-grotesk-400-normal-C910xUUL.woff',
    '../build/assets/hanken-grotesk-400-normal-CjyVwvJV.woff',
    '../build/assets/hanken-grotesk-400-normal-DI-aIsWt.woff',
    '../build/assets/hanken-grotesk-400-normal-DR7lHpW4.woff2',
    '../build/assets/hanken-grotesk-500-normal-BNvrJ0Ju.woff',
    '../build/assets/hanken-grotesk-500-normal-Bo-NxEKf.woff',
    '../build/assets/hanken-grotesk-500-normal-DJxU5DEV.woff2',
    '../build/assets/hanken-grotesk-500-normal-DLb9JDK8.woff2',
    '../build/assets/hanken-grotesk-500-normal-DkDHNoXI.woff',
    '../build/assets/hanken-grotesk-500-normal-DrDcrrxK.woff2',
    '../build/assets/hanken-grotesk-500-normal-DxwlvJEc.woff2',
    '../build/assets/hanken-grotesk-500-normal-klSdyF8A.woff',
    '../build/assets/hanken-grotesk-600-normal-CIXX6EOa.woff2',
    '../build/assets/hanken-grotesk-600-normal-CZKgvMB-.woff',
    '../build/assets/hanken-grotesk-600-normal-Cp8QQjQf.woff2',
    '../build/assets/hanken-grotesk-600-normal-DHIm05DD.woff',
    '../build/assets/hanken-grotesk-600-normal-DHaFH8q1.woff',
    '../build/assets/hanken-grotesk-600-normal-FY8kSObK.woff2',
    '../build/assets/hanken-grotesk-600-normal-NEn2C4Q3.woff',
    '../build/assets/hanken-grotesk-600-normal-gC6IfhiA.woff2',
    '../build/assets/input-otp-Ci9rC1xB.js',
    '../build/assets/jetbrains-mono-400-normal-6-qcROiO.woff',
    '../build/assets/jetbrains-mono-400-normal-B9oWc5Lo.woff',
    '../build/assets/jetbrains-mono-400-normal-BEIGL1Tu.woff2',
    '../build/assets/jetbrains-mono-400-normal-Bc8Ftmh3.woff2',
    '../build/assets/jetbrains-mono-400-normal-Bh0R7Dhr.woff',
    '../build/assets/jetbrains-mono-400-normal-ByoDsISC.woff2',
    '../build/assets/jetbrains-mono-400-normal-C190GLew.woff2',
    '../build/assets/jetbrains-mono-400-normal-C_uLvvQ5.woff2',
    '../build/assets/jetbrains-mono-400-normal-CqNFfHCs.woff',
    '../build/assets/jetbrains-mono-400-normal-V6pRDFza.woff2',
    '../build/assets/jetbrains-mono-400-normal-fXTG6kC5.woff',
    '../build/assets/jetbrains-mono-400-normal-ugxPyKxw.woff',
    '../build/assets/jetbrains-mono-500-normal-BWZEU5yA.woff2',
    '../build/assets/jetbrains-mono-500-normal-BvH7FW2L.woff2',
    '../build/assets/jetbrains-mono-500-normal-C9ShMxGR.woff2',
    '../build/assets/jetbrains-mono-500-normal-CEVyPk4Y.woff',
    '../build/assets/jetbrains-mono-500-normal-CJOVTJB7.woff',
    '../build/assets/jetbrains-mono-500-normal-Cut-4mMH.woff2',
    '../build/assets/jetbrains-mono-500-normal-D7SFKleX.woff',
    '../build/assets/jetbrains-mono-500-normal-DJqRU3vO.woff',
    '../build/assets/jetbrains-mono-500-normal-DNRqzVM1.woff',
    '../build/assets/jetbrains-mono-500-normal-DmUKJPL_.woff2',
    '../build/assets/jetbrains-mono-500-normal-JpySY46c.woff2',
    '../build/assets/jetbrains-mono-500-normal-ckzbgY84.woff',
    '../build/assets/label-C6icjWNJ.js',
    '../build/assets/spinner-GbxXCY9f.js',
    '../build/assets/textarea-BpD9y-zm.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-BneAf9nF.css',
    '../build/assets/app-Yoedpt4L.js',
    '../build/assets/Dashboard-Dz3v0iGM.js',
    '../build/assets/Welcome-BtXNgDpA.js',
    '../build/assets/Create-BeQtOycF.js',
    '../build/assets/Edit-BhAvghIe.js',
    '../build/assets/Env-BR40_Epm.js',
    '../build/assets/Index-a9rZJFF5.js',
    '../build/assets/Show-Cu8-g_GH.js',
    '../build/assets/ConfirmPassword-DUQdi0JH.js',
    '../build/assets/ForgotPassword-IzvTVgDj.js',
    '../build/assets/Login-D5T45l6Y.js',
    '../build/assets/Register-C3HMaP6e.js',
    '../build/assets/ResetPassword-CX-VGN3O.js',
    '../build/assets/TwoFactorChallenge-CPcmBWwP.js',
    '../build/assets/VerifyEmail-CKvQQWVR.js',
    '../build/assets/Create-C6QaDLs0.js',
    '../build/assets/Index-C9FkyDYL.js',
    '../build/assets/Show-VN8aU5MK.js',
    '../build/assets/Create-NQfZf-Yr.js',
    '../build/assets/Edit-DctuEHm1.js',
    '../build/assets/Index-B5vsWNCy.js',
    '../build/assets/Show-CtylvSVa.js',
    '../build/assets/Appearance-C5r3KDc2.js',
    '../build/assets/Profile-BAFj34tk.js',
    '../build/assets/Security-BDWpfRN_.js',
    '../build/assets/SourceProviders-DxURbkEM.js',
    '../build/assets/SshKeys-QTlCnZe-.js',
    '../build/assets/Team-D-Ijizgy.js',
    '../offline.html',
    '../images/icons/icon-72x72.png',
    '../images/icons/icon-96x96.png',
    '../images/icons/icon-128x128.png',
    '../images/icons/icon-144x144.png',
    '../images/icons/icon-152x152.png',
    '../images/icons/icon-192x192.png',
    '../images/icons/icon-384x384.png',
    '../images/icons/icon-512x512.png'
]

let preLoad = async function () {
    const cache = await caches.open('offline')
    return await cache.addAll(filesToCache)
}

self.addEventListener('fetch', function (event) {
    event.respondWith(
        checkResponse(event.request).catch(function () {
            return returnFromCache(event.request)
        })
    )
    event.waitUntil(addToCache(event.request))
})

let checkResponse = function (request) {
    return new Promise(function (fulfill, reject) {
        fetch(request).then(function (response) {
            if (response.status !== 404) {
                fulfill(response)
            } else {
                reject()
            }
        }, reject)
    })
}

let addToCache = async function (request) {
    const cache = await caches.open('offline')
    const response = await fetch(request)
    return await cache.put(request, response)
}

let returnFromCache = async function (request) {
    const cache = await caches.open('offline')
    const matching = await cache.match(request)
    if (!matching || matching.status == 404) {
        return cache.match('offline.html')
    } else {
        return matching
    }
}
