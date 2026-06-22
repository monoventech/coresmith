self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-DUPxdd6Y.js',
    '../build/assets/Icon-DGknEAgL.js',
    '../build/assets/InputError-BtsMRCX4.js',
    '../build/assets/PasswordInput-DfY_bDh7.js',
    '../build/assets/TextLink-CUgB7A1J.js',
    '../build/assets/button-Dd4bU7X0.js',
    '../build/assets/check-D8U6jrQ1.js',
    '../build/assets/client-TbXYfs6E.js',
    '../build/assets/create-id-dPprFLPt.js',
    '../build/assets/dialog-Bb69Nely.js',
    '../build/assets/dist-BQiyLl8o.js',
    '../build/assets/fonts-DkuEHybc.css',
    '../build/assets/input-otp--Vcj5FTC.js',
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
    '../build/assets/label-DLPy_d7U.js',
    '../build/assets/spinner-9NPvUY0d.js',
    '../build/assets/app-DkaNrS_t.css',
    '../build/assets/app-CQRgTaZC.js',
    '../build/assets/Dashboard-PMnaBD32.js',
    '../build/assets/Welcome-B-G4ozhZ.js',
    '../build/assets/ConfirmPassword-Bc-KDs2U.js',
    '../build/assets/ForgotPassword-DmrKesOk.js',
    '../build/assets/Login-hPU2Pi68.js',
    '../build/assets/Register-vzPDCZ5Y.js',
    '../build/assets/ResetPassword-28cI86Ji.js',
    '../build/assets/TwoFactorChallenge-0cDQQCL0.js',
    '../build/assets/VerifyEmail-D84MpNnU.js',
    '../build/assets/Appearance-C0iFXWK3.js',
    '../build/assets/Profile-uwCBqF4S.js',
    '../build/assets/Security-MEEyTM1K.js',
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
