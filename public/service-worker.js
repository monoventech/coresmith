self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/ActivityRow-Df1wp-Lq.js',
    '../build/assets/AppHead-DlRvivkr.js',
    '../build/assets/BackLink-DtMRtcLC.js',
    '../build/assets/EmptyState-Bhcz8QwW.js',
    '../build/assets/FieldError-Pz2ss7hH.js',
    '../build/assets/InputError-3UWOCIBE.js',
    '../build/assets/PasswordInput-CPpwbkH7.js',
    '../build/assets/PhaseIcon-jQj6Bf3C.js',
    '../build/assets/ServerHeader-B412K6ns.js',
    '../build/assets/StateDot-Qckn7zAZ.js',
    '../build/assets/TextLink-ghhcFrh7.js',
    '../build/assets/ToggleRow-CT7zxaTP.js',
    '../build/assets/badge-liTgEh0C.js',
    '../build/assets/check-CmXysWpS.js',
    '../build/assets/checkbox-BsZ8_zBw.js',
    '../build/assets/fonts-DItr_9Ts.css',
    '../build/assets/git-branch-JLgvF2a1.js',
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
    '../build/assets/hidden-input-y3Epum7_.js',
    '../build/assets/input-CWJqgyCC.js',
    '../build/assets/input-otp-DkvCfinW.js',
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
    '../build/assets/label-BC_Gv4cW.js',
    '../build/assets/native-select-C9eyi6i0.js',
    '../build/assets/octagon-alert-C3JSs6q4.js',
    '../build/assets/shield-check-CcLXggab.js',
    '../build/assets/textarea-03JHlGEZ.js',
    '../build/assets/app-B5oTuuUq.css',
    '../build/assets/BespokeSlab-Variable-DTsXCtJM.woff2',
    '../build/assets/BespokeSlab-VariableItalic-zTa5rUhx.woff2',
    '../build/assets/app-CM-4lWdI.js',
    '../build/assets/Dashboard-8xBI3i4H.js',
    '../build/assets/Install-Cqz_0O6F.js',
    '../build/assets/Welcome-BcXqIQjg.js',
    '../build/assets/Profile-DqqiXRe0.js',
    '../build/assets/Security-Bs0WBqSa.js',
    '../build/assets/Create-Bd4f5yMK.js',
    '../build/assets/Show-qojeJPGw.js',
    '../build/assets/ConfirmPassword-CZ--hseG.js',
    '../build/assets/ForgotPassword-CfLZFhiF.js',
    '../build/assets/Login-BzImkFLT.js',
    '../build/assets/Register-CJcItmKn.js',
    '../build/assets/ResetPassword-CcINYsid.js',
    '../build/assets/SetupRequired-cyanaMPG.js',
    '../build/assets/SingleSignOn-7hHFAKww.js',
    '../build/assets/TwoFactorChallenge-DuHaaGTE.js',
    '../build/assets/VerifyEmail-hOLsD5Tl.js',
    '../build/assets/Create-DzuyeKaO.js',
    '../build/assets/Show-CnKL_Qn8.js',
    '../build/assets/Index-FqYHgVup.js',
    '../build/assets/Show-DFdBlCfa.js',
    '../build/assets/Index-B2cpuTO0.js',
    '../build/assets/Activity-pIET0lkl.js',
    '../build/assets/Create-D5VPImu2.js',
    '../build/assets/Databases-BMa8MPGB.js',
    '../build/assets/Hardening-Dbg1qEdu.js',
    '../build/assets/Show-Dx_X0jaq.js',
    '../build/assets/ApiTokens-CX51zKV-.js',
    '../build/assets/Billing-va7yPUVD.js',
    '../build/assets/General-DXL_72FQ.js',
    '../build/assets/Git-Cz7IKgdf.js',
    '../build/assets/Notifications-C7TyXCI6.js',
    '../build/assets/Team-DvS7Ijbn.js',
    '../build/assets/Show-BAe2WoB4.js',
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
