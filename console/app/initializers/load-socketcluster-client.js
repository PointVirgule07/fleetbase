export function initialize(application) {
    // Check if the script already exists
    if (!document.querySelector('script[data-socketcluster-client]')) {
        
        // Block app boot until we patch the socket client
        if (application && typeof application.deferReadiness === 'function') {
            application.deferReadiness();
        }

        // Fetch script manually to control execution context (bust AMD)
        fetch('/assets/socketcluster-client.min.js')
            .then(response => response.text())
            .then(scriptContent => {
                // Temporarily hide window.define to force UMD to assign to window.socketCluster
                const originalDefine = window.define;
                window.define = undefined;
                
                try {
                    const script = document.createElement('script');
                    script.textContent = scriptContent;
                    script.setAttribute('data-socketcluster-client', '1');
                    document.body.appendChild(script);
                } finally {
                    // Restore define
                    window.define = originalDefine;
                }

                // Now patch the global instance
                if (window.socketCluster) {
                     const originalCreate = window.socketCluster.create;
                     if (!originalCreate._isPatched) {
                         window.socketCluster.create = function(options) {
                             options = options || {};
                             if (!options.authToken) {
                                 try {
                                     let sessionData = localStorage.getItem('ember_simple_auth-session');
                                     if (sessionData) {
                                         const parsed = JSON.parse(sessionData);
                                         if (parsed.authenticated && parsed.authenticated.access_token) {
                                             options.authToken = parsed.authenticated.access_token;
                                         }
                                     }
                                 } catch (e) {
                                     // Silent error
                                 }
                             }
                             return originalCreate.call(this, options);
                         };
                         window.socketCluster.create._isPatched = true;
                     }
                     
                     // Register module for AMD if needed by other consumers
                     if (window.define && window.define.amd) {
                         window.define('socketcluster-client', [], function() { return window.socketCluster; });
                     }
                }

                // Resume app boot
                if (application && typeof application.advanceReadiness === 'function') {
                    application.advanceReadiness();
                }
            })
            .catch(err => {
                // Fallback to standard tag if fetch fails
                const s = document.createElement('script');
                s.src = '/assets/socketcluster-client.min.js';
                s.setAttribute('data-socketcluster-client', '1');
                document.body.appendChild(s);

                // Resume app boot even on error
                if (application && typeof application.advanceReadiness === 'function') {
                    application.advanceReadiness();
                }
            });
    }
}

export default {
    initialize,
};
