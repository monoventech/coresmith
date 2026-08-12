self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-B8J_SDYJ.js',
    '../build/assets/ApplicationOfflineBanner-BL-RihLl.js',
    '../build/assets/ApplicationTabs-BBpckvxr.js',
    '../build/assets/BlueprintRack-BTWrR2Vj.js',
    '../build/assets/EmptyState-BVCXCptH.js',
    '../build/assets/InputError-5PK9KCut.js',
    '../build/assets/PageTabs-ZHhbQW9b.js',
    '../build/assets/PasswordInput-CAlEzIM_.js',
    '../build/assets/ServerStatusBadge-i0YIaTs6.js',
    '../build/assets/ServerTabs-Dcj38gi6.js',
    '../build/assets/StatusBadge-CtYHY2z6.js',
    '../build/assets/TextLink-C_bGsttx.js',
    '../build/assets/check-Db2T5eP3.js',
    '../build/assets/checkbox-BciTjp1V.js',
    '../build/assets/dialog-_oYpk7SW.js',
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
    '../build/assets/hidden-input-C5YBgeMH.js',
    '../build/assets/input-otp-C2fI5cv1.js',
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
    '../build/assets/label-CVSqQDaO.js',
    '../build/assets/spinner-CNUgu-WV.js',
    '../build/assets/textarea-BuwxcrJh.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-Ic5ioKOy.css',
    '../build/assets/app-BAo2oI3-.js',
    '../build/assets/Dashboard-DINYS9V1.js',
    '../build/assets/Install-BBARe50K.js',
    '../build/assets/Welcome-CV4EtGco.js',
    '../build/assets/Create-BuOCDuUq.js',
    '../build/assets/Deployments-dkRoNbyt.js',
    '../build/assets/Edit-BcHIFfhs.js',
    '../build/assets/Env-DtXBVsn0.js',
    '../build/assets/Features-DB0iWFU1.js',
    '../build/assets/Index-B4Ug2PXt.js',
    '../build/assets/Overview-oeDifWpH.js',
    '../build/assets/Show-IqGO-7Lo.js',
    '../build/assets/ConfirmPassword-CHIaqzGS.js',
    '../build/assets/ForgotPassword-uKXj6iyD.js',
    '../build/assets/Login-DcWMXmxg.js',
    '../build/assets/Register-DdRAkYmW.js',
    '../build/assets/ResetPassword-wGA2vCae.js',
    '../build/assets/SetupRequired-CFYG_3bj.js',
    '../build/assets/TwoFactorChallenge-DvSkd1UH.js',
    '../build/assets/VerifyEmail-B2h889SK.js',
    '../build/assets/Create-7EFM1Tio.js',
    '../build/assets/Index-RDA1IM0d.js',
    '../build/assets/Overview-BfhBQQm1.js',
    '../build/assets/Show-CHVm8xW1.js',
    '../build/assets/Show-BPx0u52x.js',
    '../build/assets/Create-C2WA3Heo.js',
    '../build/assets/Edit-CIMlH-Rk.js',
    '../build/assets/Firewall-eqEX9E5-.js',
    '../build/assets/Index-DHR9YSjQ.js',
    '../build/assets/Show-CxVVv3yU.js',
    '../build/assets/ApiTokens-BxpUBPvH.js',
    '../build/assets/Appearance-Z0ZN3OCN.js',
    '../build/assets/Profile-DSLjD3IO.js',
    '../build/assets/Security-Cis7aUHr.js',
    '../build/assets/SourceProviders-BuLSpvMo.js',
    '../build/assets/SshKeys-C0RNQVbP.js',
    '../build/assets/Team-BK6H49uQ.js',
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
