self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-KCEi1nmA.js',
    '../build/assets/BlueprintRack-CilqEFcd.js',
    '../build/assets/EmptyState-C8HbKgJC.js',
    '../build/assets/InputError-D15mKj6Y.js',
    '../build/assets/PasswordInput-Dv8kMnad.js',
    '../build/assets/ServerStatusBadge-CD4gCky5.js',
    '../build/assets/StatusBadge-PrdN7-b2.js',
    '../build/assets/TextLink-C2NQXeN_.js',
    '../build/assets/check-DEd92Mv3.js',
    '../build/assets/checkbox-DrtWLEci.js',
    '../build/assets/dialog-CxJUnrTI.js',
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
    '../build/assets/hidden-input-BtGGMNne.js',
    '../build/assets/input-otp-DgEDbjKo.js',
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
    '../build/assets/label-PaaFGpRA.js',
    '../build/assets/spinner-B8jff94_.js',
    '../build/assets/textarea-DMsHtok1.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-DjQufnhH.css',
    '../build/assets/app-CwNE6Lco.js',
    '../build/assets/Dashboard-YTBojRgC.js',
    '../build/assets/Install-CkS1JNLI.js',
    '../build/assets/Welcome-B3j7mRlZ.js',
    '../build/assets/Create-Bll8oo6b.js',
    '../build/assets/Edit-IWia_VJh.js',
    '../build/assets/Env-X15c4hbi.js',
    '../build/assets/Index-WXtW6cxC.js',
    '../build/assets/Overview-BeEB3iC9.js',
    '../build/assets/Show-CZF8fbqK.js',
    '../build/assets/ConfirmPassword-BkOKqEhs.js',
    '../build/assets/ForgotPassword-DnXqnA38.js',
    '../build/assets/Login-Bb8NzIoI.js',
    '../build/assets/Register-DmFh_5Ts.js',
    '../build/assets/ResetPassword-sefkniQt.js',
    '../build/assets/SetupRequired-DlcO2Ov5.js',
    '../build/assets/TwoFactorChallenge-BSTFHd3M.js',
    '../build/assets/VerifyEmail-BC95hQo7.js',
    '../build/assets/Create-DKvVFY19.js',
    '../build/assets/Index-Duz8obZb.js',
    '../build/assets/Overview-i7P63gPc.js',
    '../build/assets/Show-BU_wO6LF.js',
    '../build/assets/Show-BIACTl5c.js',
    '../build/assets/Create-pUSHPHwS.js',
    '../build/assets/Edit-BErrf4FW.js',
    '../build/assets/Index-CDasvY9e.js',
    '../build/assets/Show-DHD-yKG4.js',
    '../build/assets/ApiTokens-DWyJqae8.js',
    '../build/assets/Appearance-Bx00BIoD.js',
    '../build/assets/Profile-CloQL5Sv.js',
    '../build/assets/Security-BK-Wq-3Q.js',
    '../build/assets/SourceProviders-BjNgF_Iu.js',
    '../build/assets/SshKeys-Dv8V83U0.js',
    '../build/assets/Team-CgO2q_dH.js',
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
