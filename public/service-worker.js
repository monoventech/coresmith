self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-CMCi7vpv.js',
    '../build/assets/InputError-BiRQYVKx.js',
    '../build/assets/PasswordInput-DkhHzy-N.js',
    '../build/assets/TextLink-DvE_liQc.js',
    '../build/assets/check-BvGoBz8O.js',
    '../build/assets/dialog-C0rM1I_F.js',
    '../build/assets/fonts-DkuEHybc.css',
    '../build/assets/input-otp-H3-nF-l0.js',
    '../build/assets/instrument-sans-400-normal-D1W7dsQl.woff',
    '../build/assets/instrument-sans-400-normal-DRC__1Mx.woff2',
    '../build/assets/instrument-sans-400-normal-Q_nF8v4l.woff2',
    '../build/assets/instrument-sans-400-normal-r32jotim.woff',
    '../build/assets/instrument-sans-500-normal-CAxz3nsc.woff',
    '../build/assets/instrument-sans-500-normal-CTEe1bJa.woff2',
    '../build/assets/instrument-sans-500-normal-Dk9ku72i.woff2',
    '../build/assets/instrument-sans-500-normal-Z6ESRlEs.woff',
    '../build/assets/instrument-sans-600-normal-B7fBEWYG.woff2',
    '../build/assets/instrument-sans-600-normal-B9e8oLYv.woff',
    '../build/assets/instrument-sans-600-normal-BsaQcF38.woff2',
    '../build/assets/instrument-sans-600-normal-DMks36a2.woff',
    '../build/assets/label-CGYfZmj2.js',
    '../build/assets/spinner-hMjTgdw1.js',
    '../build/assets/app-DkaNrS_t.css',
    '../build/assets/app-CCmi7O-H.js',
    '../build/assets/Dashboard-DU2LFPVd.js',
    '../build/assets/Welcome-BO6LGffu.js',
    '../build/assets/ConfirmPassword-DbPYxBaY.js',
    '../build/assets/ForgotPassword-DpEQEkIN.js',
    '../build/assets/Login-4Tks7Hu1.js',
    '../build/assets/Register-B-xa8CP_.js',
    '../build/assets/ResetPassword-BrTEIgEg.js',
    '../build/assets/TwoFactorChallenge-BoKE9OAb.js',
    '../build/assets/VerifyEmail-yD96MPLV.js',
    '../build/assets/Appearance-CbMKsnkm.js',
    '../build/assets/Profile-CmXLH2n3.js',
    '../build/assets/Security-bQOhR1_k.js',
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
