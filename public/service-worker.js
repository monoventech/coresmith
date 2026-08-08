self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-DsqAly8I.js',
    '../build/assets/BlueprintRack-DLHHReWi.js',
    '../build/assets/EmptyState-Bse6yCdI.js',
    '../build/assets/InputError-CdQDwbDy.js',
    '../build/assets/PasswordInput-C7SjMpu9.js',
    '../build/assets/ServerStatusBadge-C-Q0CpH4.js',
    '../build/assets/StatusBadge-Db-bq_EB.js',
    '../build/assets/TextLink-BPIaHRlc.js',
    '../build/assets/check-Dh9sr3NW.js',
    '../build/assets/checkbox-AIKsnHqM.js',
    '../build/assets/dialog-BEOmOLop.js',
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
    '../build/assets/input-otp-CQHYNKfx.js',
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
    '../build/assets/label-CYtVNKeW.js',
    '../build/assets/spinner--CD5Y457.js',
    '../build/assets/textarea-DghQgYhc.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-CEXOveXO.css',
    '../build/assets/app-D_yAxtrk.js',
    '../build/assets/Dashboard-Bnk32uAR.js',
    '../build/assets/Install-BRYlcz0M.js',
    '../build/assets/Welcome-p-ESe-_4.js',
    '../build/assets/Create-DcyOHvcN.js',
    '../build/assets/Edit-BW8oEo3R.js',
    '../build/assets/Env-BXAA_phb.js',
    '../build/assets/Index-QgSmbKRU.js',
    '../build/assets/Show-dvyOa0iu.js',
    '../build/assets/ConfirmPassword-DyiJMzDq.js',
    '../build/assets/ForgotPassword-mRa4O54q.js',
    '../build/assets/Login-E8NpFsUv.js',
    '../build/assets/Register-DvhnYTXJ.js',
    '../build/assets/ResetPassword-BaEADWPl.js',
    '../build/assets/SetupRequired-KXOUmgql.js',
    '../build/assets/TwoFactorChallenge-BbLum95d.js',
    '../build/assets/VerifyEmail-TiXj_Uvd.js',
    '../build/assets/Create-CtO9f09w.js',
    '../build/assets/Index-cpLd1P5r.js',
    '../build/assets/Show-BnIgSVCY.js',
    '../build/assets/Show-BNxbh98D.js',
    '../build/assets/Create-c9WV_upd.js',
    '../build/assets/Edit-nI1gVPPn.js',
    '../build/assets/Index-Cj8uZjLP.js',
    '../build/assets/Show-CcNrYL08.js',
    '../build/assets/ApiTokens-BzswyGnd.js',
    '../build/assets/Appearance-mDdzNPwi.js',
    '../build/assets/Profile-oXy6wd3r.js',
    '../build/assets/Security-DpBJgDSL.js',
    '../build/assets/SourceProviders-LzGrvUkO.js',
    '../build/assets/SshKeys-ByC0va9w.js',
    '../build/assets/Team-koTmux03.js',
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
