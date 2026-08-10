self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-Ch1AW3ax.js',
    '../build/assets/BlueprintRack-Dqpa2sS1.js',
    '../build/assets/EmptyState-Bv4WKqDZ.js',
    '../build/assets/InputError-DG72QLtu.js',
    '../build/assets/PasswordInput-MX0ON79G.js',
    '../build/assets/ServerStatusBadge-HCCg8p5D.js',
    '../build/assets/StatusBadge-Cd-fcWUG.js',
    '../build/assets/TextLink-D8lgBj-M.js',
    '../build/assets/check-BxtjMC3p.js',
    '../build/assets/checkbox-CfmoU_QJ.js',
    '../build/assets/dialog-DBTxQ1QS.js',
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
    '../build/assets/hidden-input-DyDk6Psv.js',
    '../build/assets/input-otp-BVcBgXxN.js',
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
    '../build/assets/label-Dk2H_GW8.js',
    '../build/assets/spinner-Dp1NSKIG.js',
    '../build/assets/textarea-BC7KEim3.js',
    '../build/assets/zilla-slab-500-normal-B8-aa0aC.woff',
    '../build/assets/zilla-slab-500-normal-BtCblgyy.woff2',
    '../build/assets/zilla-slab-500-normal-CU92Uk-1.woff',
    '../build/assets/zilla-slab-500-normal-CzZRcrUb.woff2',
    '../build/assets/zilla-slab-600-normal-9hyyVsz0.woff2',
    '../build/assets/zilla-slab-600-normal-BRgn6X7z.woff2',
    '../build/assets/zilla-slab-600-normal-DTnoEnY3.woff',
    '../build/assets/zilla-slab-600-normal-iLPAhCz6.woff',
    '../build/assets/app-C1J3D8nN.css',
    '../build/assets/app-Dr6-_Aoz.js',
    '../build/assets/Dashboard-MAekOPEa.js',
    '../build/assets/Install-DST2ypsb.js',
    '../build/assets/Welcome-CX9z176J.js',
    '../build/assets/Create-CBfTSznP.js',
    '../build/assets/Edit-BFaIw3OP.js',
    '../build/assets/Env-BBTDgRlp.js',
    '../build/assets/Index-BR4JTn0_.js',
    '../build/assets/Overview-Dnz6JoXL.js',
    '../build/assets/Show-BfSOjcVt.js',
    '../build/assets/ConfirmPassword-j5cosObK.js',
    '../build/assets/ForgotPassword-DCX7J4ZQ.js',
    '../build/assets/Login-CqBVqQvq.js',
    '../build/assets/Register-D_8Wf9_r.js',
    '../build/assets/ResetPassword-Dv7psUfV.js',
    '../build/assets/SetupRequired-D_NpqNRG.js',
    '../build/assets/TwoFactorChallenge-z-nty13e.js',
    '../build/assets/VerifyEmail-B9mclNXv.js',
    '../build/assets/Create-BDnFT1VB.js',
    '../build/assets/Index-B1cpuUfh.js',
    '../build/assets/Overview-BU2HSTaE.js',
    '../build/assets/Show-BtWXudqu.js',
    '../build/assets/Show-C7X9H1OQ.js',
    '../build/assets/Create-Cnox8QfA.js',
    '../build/assets/Edit-CIXMc-VT.js',
    '../build/assets/Index-CIIRPo_e.js',
    '../build/assets/Show-D-tPYthq.js',
    '../build/assets/ApiTokens-Chx3-EXL.js',
    '../build/assets/Appearance-Bnpm-pDl.js',
    '../build/assets/Profile-s_knvQLf.js',
    '../build/assets/Security-CgMi8qbQ.js',
    '../build/assets/SourceProviders-C66K3EVu.js',
    '../build/assets/SshKeys-ifuTxfJD.js',
    '../build/assets/Team-2oOim4K4.js',
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
