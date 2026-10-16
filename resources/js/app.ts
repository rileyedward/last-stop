import { createInertiaApp } from '@inertiajs/vue3';
import { initializeFlashToast } from '@/lib/flashToast';
import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) =>
        title && title !== appName ? `${title} - ${appName}` : appName,
    // Every page, game and admin alike, brings its own frame.
    layout: () => null,
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#F4B740',
    },
});

// This will listen for flash toast data from the server...
initializeFlashToast();
