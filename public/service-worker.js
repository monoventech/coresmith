self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-BTR2Unby.js',
    '../build/assets/BlueprintRack-Be8JqOAW.js',
    '../build/assets/EmptyState-DsOHSeIT.js',
    '../build/assets/InputError-QQjeOMcx.js',
    '../build/assets/PasswordInput-C8P_sZhK.js',
    '../build/assets/ServerStatusBadge-FaIhfPUT.js',
    '../build/assets/StatusBadge-Cw091k_E.js',
    '../build/assets/TextLink-B0w3eEvC.js',
    '../build/assets/check-CZoROCmZ.js',
    '../build/assets/checkbox-CrUwv4my.js',
    '../build/assets/dialog-x4jJE8md.js',
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
    '../build/assets/input-otp-B6L2X5rY.js',
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
    '../build/assets/label-Nx4_ZP59.js',
    '../build/assets/spinner-BVqFTyhW.js',
    '../build/assets/textarea-BiMR7Nup.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-D_y0ccci.css',
    '../build/assets/app-CXDN1nzr.js',
    '../build/assets/Dashboard-BnHjHc7b.js',
    '../build/assets/Welcome-qnOc3sHm.js',
    '../build/assets/Create-XiIDAcDb.js',
    '../build/assets/Edit-jF4xH3Lf.js',
    '../build/assets/Env-7gjsdZg4.js',
    '../build/assets/Index-DlXOkKRU.js',
    '../build/assets/Show-DzPRTloX.js',
    '../build/assets/ConfirmPassword-DwsbUPQ3.js',
    '../build/assets/ForgotPassword-CoCsGSRO.js',
    '../build/assets/Login-BPKpWmqR.js',
    '../build/assets/Register-BoFC-o6q.js',
    '../build/assets/ResetPassword-CYTmUKVd.js',
    '../build/assets/TwoFactorChallenge-owMBWrV3.js',
    '../build/assets/VerifyEmail-CUBknyjk.js',
    '../build/assets/Create-B9o_k8HN.js',
    '../build/assets/Index-DkaVaQWW.js',
    '../build/assets/Show-B6pyS_4x.js',
    '../build/assets/Show-BnGDmPju.js',
    '../build/assets/Create-D69F2fLM.js',
    '../build/assets/Edit-B7v02C-t.js',
    '../build/assets/Index-CiNvyRkr.js',
    '../build/assets/Show-bVw1_iGZ.js',
    '../build/assets/Appearance-DFe_-wqX.js',
    '../build/assets/Profile-CVl_wV4l.js',
    '../build/assets/Security-D4W3B9nW.js',
    '../build/assets/SourceProviders-C_j3nPDc.js',
    '../build/assets/SshKeys-DVzcnDTo.js',
    '../build/assets/Team-B_mLtQJP.js',
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
