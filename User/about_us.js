

(function() {
    'use strict';

    // SCROLL REVEAL //
    function initScrollReveal() {
        const revealElements = document.querySelectorAll('.reveal, .reveal-left, .reveal-right');
        
        function checkReveal() {
            const windowHeight = window.innerHeight;
            revealElements.forEach(element => {
                const elementTop = element.getBoundingClientRect().top;
                if (elementTop < windowHeight - 100) {
                    element.classList.add('active');
                }
            });
        }

        window.addEventListener('scroll', checkReveal);
        window.addEventListener('load', checkReveal);
        checkReveal();
    }

    //  DELAY ANIMATIONS //
    function initDelayAnimations() {
        document.querySelectorAll('[data-delay]').forEach(element => {
            element.style.transitionDelay = element.getAttribute('data-delay') + 'ms';
        });
    }


    // INITIALIZE //
    function init() {
        initScrollReveal();
        initDelayAnimations();
         
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();