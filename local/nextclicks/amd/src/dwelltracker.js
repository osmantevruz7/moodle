define(['core/ajax'], function(Ajax) {
    const PING_INTERVAL_MS = 10000;
    const MAX_CHUNK_SECONDS = 120;

    const isActive = () => document.visibilityState === 'visible' && document.hasFocus();

    const sendPing = (cmid, seconds) => {
        if (!seconds || seconds <= 0) {
            return;
        }
        const chunk = Math.min(MAX_CHUNK_SECONDS, Math.max(1, Math.floor(seconds)));
        Ajax.call([{
            methodname: 'local_nextclicks_track_dwell',
            args: {
                cmid: cmid,
                seconds: chunk,
            },
        }], false, false);
    };

    const init = (cmid) => {
        if (!cmid) {
            return;
        }

        let lastTick = Date.now();

        const flush = () => {
            const now = Date.now();
            const elapsedSeconds = (now - lastTick) / 1000;
            lastTick = now;
            if (isActive()) {
                sendPing(cmid, elapsedSeconds);
            }
        };

        setInterval(flush, PING_INTERVAL_MS);

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                flush();
            } else {
                lastTick = Date.now();
            }
        });

        // pagehide fires more reliably than beforeunload on mobile browsers
        // (Android Chrome, iOS Safari) when the user swipes away or closes the tab.
        window.addEventListener('pagehide', function() {
            flush();
        });
    };

    return {init: init};
});
