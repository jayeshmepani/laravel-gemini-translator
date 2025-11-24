document.addEventListener('DOMContentLoaded', function () {
    const backToTopButton = document.querySelector('.back-to-top');
    const hamburger = document.getElementById('hamburger-menu');
    const navLinks = document.getElementById('nav-links');
    const themeToggle = document.getElementById('theme-toggle');
    const htmlElement = document.documentElement;

    // --------------------
    // THEME TOGGLE
    // --------------------
    const applyTheme = (theme) => {
        if (theme === 'dark') {
            htmlElement.classList.add('dark');
        } else {
            htmlElement.classList.remove('dark');
        }
        localStorage.setItem('theme', theme);
    };

    const toggleTheme = () => {
        const currentTheme = htmlElement.classList.contains('dark') ? 'light' : 'dark';
        applyTheme(currentTheme);
    };

    const storedTheme = localStorage.getItem('theme');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (storedTheme) {
        applyTheme(storedTheme);
    } else {
        applyTheme(systemPrefersDark ? 'dark' : 'light');
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', toggleTheme);
    }

    // --------------------
    // MOBILE NAV
    // --------------------
    if (hamburger && navLinks) {
        hamburger.addEventListener('click', function () {
            navLinks.classList.toggle('active');
            document.body.classList.toggle('nav-open');
        });
    }

    // --------------------
    // VIEW TRANSITION WRAPPER
    // --------------------
    const runWithViewTransition = (fn) => {
        if (!document.startViewTransition) {
            // No support → just do normal behavior (with smooth scroll)
            fn(true);
            return;
        }

        document.startViewTransition(() => {
            fn(false);
        });
    };

    // --------------------
    // NAV LINKS + SMOOTH SECTION SWITCH
    // --------------------
    const sections = document.querySelectorAll('section[id]');
    const navLinkElements = document.querySelectorAll('nav a[href^="#"]');

    navLinkElements.forEach(link => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href');
            if (!href || !href.startsWith('#')) {
                return;
            }

            event.preventDefault();

            const targetId = href.slice(1);
            const target = document.getElementById(targetId);
            if (!target) return;

            runWithViewTransition((noVT) => {
                if (noVT) {
                    // Fallback: normal smooth scroll
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    // Inside View Transition: instant jump, animation handles smoothness
                    target.scrollIntoView({ behavior: 'instant', block: 'start' });
                }

                // Keep URL hash in sync without extra scrolling
                history.pushState(null, '', `#${targetId}`);
            });

            // Close mobile nav if open
            if (navLinks && navLinks.classList.contains('active')) {
                navLinks.classList.remove('active');
                document.body.classList.remove('nav-open');
            }
        });
    });

    // --------------------
    // SCROLL-SPY + BACK TO TOP
    // --------------------
    const handleScroll = () => {
        if (backToTopButton) {
            if (window.pageYOffset > 300) {
                backToTopButton.classList.add('visible');
            } else {
                backToTopButton.classList.remove('visible');
            }
        }

        let current = '';
        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            if (window.pageYOffset >= sectionTop - 100) {
                current = section.getAttribute('id');
            }
        });

        navLinkElements.forEach(link => {
            link.classList.remove('active');
            if (current && link.getAttribute('href') === '#' + current) {
                link.classList.add('active');
            }
        });
    };

    window.addEventListener('scroll', handleScroll, { passive: true });

    // --------------------
    // BACK/FORWARD HASH NAVIGATION WITH VIEW TRANSITION
    // --------------------
    window.addEventListener('popstate', () => {
        const hash = window.location.hash.slice(1);
        const target = hash ? document.getElementById(hash) : null;
        if (!target) return;

        runWithViewTransition((noVT) => {
            if (noVT) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                target.scrollIntoView({ behavior: 'instant', block: 'start' });
            }
        });
    });

    const initialHash = window.location.hash.slice(1);
    if (initialHash) {
        const initialSection = document.getElementById(initialHash);
        if (initialSection) {
            initialSection.scrollIntoView({ behavior: 'instant', block: 'start' });
        }
    }
});

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
