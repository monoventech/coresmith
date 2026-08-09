self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-D1L3_41V.js',
    '../build/assets/BlueprintRack-CujEzCXG.js',
    '../build/assets/EmptyState-DOfDkXj6.js',
    '../build/assets/InputError-DutrpvFo.js',
    '../build/assets/PasswordInput-CfRXx2RQ.js',
    '../build/assets/ServerStatusBadge-Xnv3HNIB.js',
    '../build/assets/StatusBadge-D7ZyG3oS.js',
    '../build/assets/TextLink-BUZRyS_I.js',
    '../build/assets/check-CLQOdpg6.js',
    '../build/assets/checkbox-Bku6dDG5.js',
    '../build/assets/dialog-o83M3Nqu.js',
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
    '../build/assets/hidden-input-DK5aOnxT.js',
    '../build/assets/input-otp-DVdHYkpg.js',
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
    '../build/assets/label-C_-oBSvD.js',
    '../build/assets/spinner-tNSo3HMc.js',
    '../build/assets/textarea-B1dcE3v-.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-BGv-0Z1S.css',
    '../build/assets/app-Bn8nFvoO.js',
    '../build/assets/Dashboard-BjCP74Wo.js',
    '../build/assets/Install-p0d193B8.js',
    '../build/assets/Welcome-gG4pFnN9.js',
    '../build/assets/Create-6scngMNU.js',
    '../build/assets/Edit-D9kgKBYK.js',
    '../build/assets/Env-Cetv7HvS.js',
    '../build/assets/Index-I8qrVHae.js',
    '../build/assets/Overview-5nJ1FRa_.js',
    '../build/assets/Show-DxHEyhhO.js',
    '../build/assets/ConfirmPassword-DW0qj2yq.js',
    '../build/assets/ForgotPassword-DDdFMBHp.js',
    '../build/assets/Login-BaoThMDZ.js',
    '../build/assets/Register-B7ObPXj1.js',
    '../build/assets/ResetPassword-Cux0-eDK.js',
    '../build/assets/SetupRequired-VEDvh4H9.js',
    '../build/assets/TwoFactorChallenge-BDrbjCZy.js',
    '../build/assets/VerifyEmail-CiV1yEzZ.js',
    '../build/assets/Create-xOELaPte.js',
    '../build/assets/Index-BHiXpoF-.js',
    '../build/assets/Overview-uiNNKXY3.js',
    '../build/assets/Show-Dq_c2o_j.js',
    '../build/assets/Show-DCbWmWMv.js',
    '../build/assets/Create-BEWix270.js',
    '../build/assets/Edit-QFD2X27r.js',
    '../build/assets/Index-D3iFxyZ7.js',
    '../build/assets/Show-D7BALQ1B.js',
    '../build/assets/ApiTokens-Dugxb3jb.js',
    '../build/assets/Appearance-B-pw7vQF.js',
    '../build/assets/Profile-BpGm5tXg.js',
    '../build/assets/Security-BeYvrdnF.js',
    '../build/assets/SourceProviders-D3hvCoGZ.js',
    '../build/assets/SshKeys-DboybBdg.js',
    '../build/assets/Team-BNHRC8Rt.js',
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
