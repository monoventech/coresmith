self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/ActivityRow-Baf8kXlQ.js',
    '../build/assets/AppHead-CO4NFXO-.js',
    '../build/assets/BackLink-D15ps5Xv.js',
    '../build/assets/EmptyState-LgRevi-w.js',
    '../build/assets/FieldError-DqWB96i_.js',
    '../build/assets/InputError-CKvX9JoX.js',
    '../build/assets/PasswordInput-BEKWg25w.js',
    '../build/assets/PhaseIcon-CAlJ9HVQ.js',
    '../build/assets/ServerHeader-ffHUPFEF.js',
    '../build/assets/StateDot-C0m_8qp-.js',
    '../build/assets/TextLink-BDPuoUWL.js',
    '../build/assets/ToggleRow-a9-wj_tu.js',
    '../build/assets/badge-C2wP2JGX.js',
    '../build/assets/check-Bv4WL6vH.js',
    '../build/assets/checkbox-BaYmVbH3.js',
    '../build/assets/circle-DwswqPmf.js',
    '../build/assets/fonts-DItr_9Ts.css',
    '../build/assets/git-branch-CK07BWo4.js',
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
    '../build/assets/hidden-input-CLDLGfgb.js',
    '../build/assets/input-DjC-ITEI.js',
    '../build/assets/input-otp-UUxS_C2C.js',
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
    '../build/assets/label-oOAqn9U3.js',
    '../build/assets/native-select-DUupFiWD.js',
    '../build/assets/octagon-alert-oGm5AOd5.js',
    '../build/assets/shield-check-BWRQX__k.js',
    '../build/assets/textarea-Bjq7OmTO.js',
    '../build/assets/app-CmchmlHs.css',
    '../build/assets/BespokeSlab-Variable-DTsXCtJM.woff2',
    '../build/assets/BespokeSlab-VariableItalic-zTa5rUhx.woff2',
    '../build/assets/app-DcR_QKa_.js',
    '../build/assets/Dashboard-SSJ2CYyw.js',
    '../build/assets/Install-DN2jfSHo.js',
    '../build/assets/Welcome-BNDFJ2nw.js',
    '../build/assets/Profile-CoXuCcH9.js',
    '../build/assets/Security-CjrQ44kS.js',
    '../build/assets/Create-C3-qNdv_.js',
    '../build/assets/Show-DKrBpLK8.js',
    '../build/assets/ConfirmPassword-DfrBqikl.js',
    '../build/assets/ForgotPassword-B0fJ4lYE.js',
    '../build/assets/Login-NA-_3wZt.js',
    '../build/assets/Register-CwuElimQ.js',
    '../build/assets/ResetPassword-zzHYZTcR.js',
    '../build/assets/SetupRequired-DaYPD9b4.js',
    '../build/assets/SingleSignOn-C36_Zyv5.js',
    '../build/assets/TwoFactorChallenge-ChLvjo5P.js',
    '../build/assets/VerifyEmail-DB5sMpVc.js',
    '../build/assets/Create-D1beKOS0.js',
    '../build/assets/Show-DCen3Anh.js',
    '../build/assets/Index-DICGKDgT.js',
    '../build/assets/Show-B5j8Wv9o.js',
    '../build/assets/Index-BZ13Dmhf.js',
    '../build/assets/Activity-DXLraVGd.js',
    '../build/assets/Create-BajINYE9.js',
    '../build/assets/Databases-CkU3f3jU.js',
    '../build/assets/Hardening-D2q3UoPk.js',
    '../build/assets/Show-_7km21wE.js',
    '../build/assets/ApiTokens-DB3_3j8u.js',
    '../build/assets/Billing-_ppSLZbj.js',
    '../build/assets/General-Kr_H0ots.js',
    '../build/assets/Git-6EjQcat0.js',
    '../build/assets/Notifications-Cq2vn82F.js',
    '../build/assets/Team-sIu8NsEt.js',
    '../build/assets/Show-DUmxtRqI.js',
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
