self.addEventListener('install', function (event) {
    event.waitUntil(preLoad())
})

let filesToCache = [
    '../build/assets/AppHead-Bgw-t0Jb.js',
    '../build/assets/InputError-BENlIXzY.js',
    '../build/assets/PasswordInput-CiWbCmUP.js',
    '../build/assets/TextLink-CEd1MCae.js',
    '../build/assets/button-CBRkzz2I.js',
    '../build/assets/client-C9TtMHCI.js',
    '../build/assets/create-id-kubriaz2.js',
    '../build/assets/dialog-CQ_AuX3Z.js',
    '../build/assets/dist-DiAGCxc0.js',
    '../build/assets/fonts-DkuEHybc.css',
    '../build/assets/input-otp-CRqc_F7Q.js',
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
    '../build/assets/label-Dyrdytab.js',
    '../build/assets/legacy-DsZnAgXj.js',
    '../build/assets/spinner-IY6ss7rn.js',
    '../build/assets/app-DW8I8_0A.css',
    '../build/assets/app-C5PrZsTT.js',
    '../build/assets/Dashboard-BeRGvVv7.js',
    '../build/assets/Welcome-Bo9JFwQO.js',
    '../build/assets/ConfirmPassword-Du9gXo-y.js',
    '../build/assets/ForgotPassword-794lW8mC.js',
    '../build/assets/Login-Dtdc7jRM.js',
    '../build/assets/Register-CQr6gxCX.js',
    '../build/assets/ResetPassword-DeHpEOJ8.js',
    '../build/assets/TwoFactorChallenge-BmKRu7EQ.js',
    '../build/assets/VerifyEmail-CZFRA22f.js',
    '../build/assets/Appearance-Ba__OrIE.js',
    '../build/assets/Profile-CT3QZE7V.js',
    '../build/assets/Security-CbZX5B-Q.js',
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
