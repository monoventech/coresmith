self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-DQ7jnvuW.js',
    '../build/assets/ApplicationOfflineBanner-DB7ERlgV.js',
    '../build/assets/ApplicationTabs-D5zCE2uN.js',
    '../build/assets/BlueprintRack-DyuNiWzv.js',
    '../build/assets/EmptyState-EXr6ptCI.js',
    '../build/assets/InputError-DK6b_5jQ.js',
    '../build/assets/PageTabs-BV3WsvuR.js',
    '../build/assets/PasswordInput-3Notw-pa.js',
    '../build/assets/ServerStatusBadge-CS-17qve.js',
    '../build/assets/ServerTabs-Cpl2ozYC.js',
    '../build/assets/StatusBadge-jPlGq7US.js',
    '../build/assets/TextLink-B3mSgqo0.js',
    '../build/assets/check-BOk2pST4.js',
    '../build/assets/checkbox-C6hW7iTA.js',
    '../build/assets/dialog-DK7Pvy1A.js',
    '../build/assets/fonts-DItr_9Ts.css',
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
    '../build/assets/hidden-input-ZmhZVkS0.js',
    '../build/assets/input-otp-DTE-W6PP.js',
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
    '../build/assets/label-DbnJm1pM.js',
    '../build/assets/spinner-On_zPZNB.js',
    '../build/assets/textarea-DrLisFPQ.js',
    '../build/assets/app-B3mHLPF0.css',
    '../build/assets/BespokeSlab-Variable-DTsXCtJM.woff2',
    '../build/assets/BespokeSlab-VariableItalic-zTa5rUhx.woff2',
    '../build/assets/app-BtDmV8Kz.js',
    '../build/assets/Dashboard-ToRb7q-Q.js',
    '../build/assets/Install-DtvyAr44.js',
    '../build/assets/Welcome-C6V3FaNy.js',
    '../build/assets/Create-R4-rSDH7.js',
    '../build/assets/Deployments-JuVypyxX.js',
    '../build/assets/Edit-CXE3wHla.js',
    '../build/assets/Env-DH8Uhldw.js',
    '../build/assets/Features-BySL6sea.js',
    '../build/assets/Index-CLxAuZvw.js',
    '../build/assets/Overview-CBxQPaFE.js',
    '../build/assets/Show-Cv7AMlXp.js',
    '../build/assets/ConfirmPassword-BCIuBfIy.js',
    '../build/assets/ForgotPassword-DjgwjxIR.js',
    '../build/assets/Login-gOSWIw99.js',
    '../build/assets/Register-EjDQnJe4.js',
    '../build/assets/ResetPassword-fxTnrPcL.js',
    '../build/assets/SetupRequired-B3wdJBqu.js',
    '../build/assets/TwoFactorChallenge-ChScU14F.js',
    '../build/assets/VerifyEmail-BBVOyst2.js',
    '../build/assets/Create-C-_FzZxs.js',
    '../build/assets/Index-Br1G9LK3.js',
    '../build/assets/Overview-CCaqFxwO.js',
    '../build/assets/Show-H0MeqYtJ.js',
    '../build/assets/Show-B5Auoilm.js',
    '../build/assets/Create-39Jv_v3v.js',
    '../build/assets/Edit-VXx9W1Hl.js',
    '../build/assets/Firewall-B0K9yzeZ.js',
    '../build/assets/Index-HAC2bJb0.js',
    '../build/assets/Show-sYNifUz0.js',
    '../build/assets/ApiTokens-_s-KnISl.js',
    '../build/assets/Appearance-BL915pNY.js',
    '../build/assets/Profile-C4oJkDjS.js',
    '../build/assets/Security-B32xzpZl.js',
    '../build/assets/SourceProviders-CJ_IpOYY.js',
    '../build/assets/SshKeys-CUuDKMd4.js',
    '../build/assets/Team-D4KvrHir.js',
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
