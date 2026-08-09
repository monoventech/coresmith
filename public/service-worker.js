self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-CirWhYpQ.js',
    '../build/assets/BlueprintRack-1JQb49Uo.js',
    '../build/assets/EmptyState-BoXjgHXd.js',
    '../build/assets/InputError-CjarQP_j.js',
    '../build/assets/PasswordInput-oNEuNfaF.js',
    '../build/assets/ServerStatusBadge-DC1qD0sp.js',
    '../build/assets/StatusBadge-Dx0Sbg8L.js',
    '../build/assets/TextLink-C0XIWIZC.js',
    '../build/assets/check-CLXUQ29D.js',
    '../build/assets/checkbox-D5aY-_UK.js',
    '../build/assets/dialog-DaLFlOXo.js',
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
    '../build/assets/hidden-input-BxZt7fSd.js',
    '../build/assets/input-otp-DANTbv_A.js',
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
    '../build/assets/label-rpcH8j7m.js',
    '../build/assets/spinner-0Aqfg0j6.js',
    '../build/assets/textarea-Bkd2xQLm.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-BHIKBRRx.css',
    '../build/assets/app-DH8odOqJ.js',
    '../build/assets/Dashboard-D3p9qjTh.js',
    '../build/assets/Install-Jfj-AXam.js',
    '../build/assets/Welcome-DvEJGdxO.js',
    '../build/assets/Create-5m-C8mq7.js',
    '../build/assets/Edit-CgPC3LE7.js',
    '../build/assets/Env-oN7ezGCq.js',
    '../build/assets/Index-lZbJuS19.js',
    '../build/assets/Overview-u-xY_0q5.js',
    '../build/assets/Show-BPIS-FRa.js',
    '../build/assets/ConfirmPassword-CInscLox.js',
    '../build/assets/ForgotPassword-DPDtCIAW.js',
    '../build/assets/Login-BF7Qx4Cl.js',
    '../build/assets/Register-DF-GxP8d.js',
    '../build/assets/ResetPassword-DGq4ZQau.js',
    '../build/assets/SetupRequired-6pdKoJ6-.js',
    '../build/assets/TwoFactorChallenge-D6Is13gs.js',
    '../build/assets/VerifyEmail-wmzwLKAU.js',
    '../build/assets/Create-D4fqJqhg.js',
    '../build/assets/Index-DqkICTXn.js',
    '../build/assets/Overview-ZCBqtHzT.js',
    '../build/assets/Show-BEez3xpD.js',
    '../build/assets/Show-C5X4lLnm.js',
    '../build/assets/Create-B6ttXlG3.js',
    '../build/assets/Edit-DZp2LO8n.js',
    '../build/assets/Index-DMXv4bQS.js',
    '../build/assets/Show-LXi4skC-.js',
    '../build/assets/ApiTokens-D_PoTX_Q.js',
    '../build/assets/Appearance-C2itHoYZ.js',
    '../build/assets/Profile-CsMqC2Yd.js',
    '../build/assets/Security-CEWxOcN-.js',
    '../build/assets/SourceProviders-Cxs1jg1B.js',
    '../build/assets/SshKeys-CiOK_5Di.js',
    '../build/assets/Team-C2BJEcvL.js',
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
