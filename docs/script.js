document.addEventListener('DOMContentLoaded', function () {
    const backToTopButton = document.querySelector('.back-to-top');
    const hamburger = document.getElementById('hamburger-menu');
    const navLinks = document.getElementById('nav-links');
    const themeToggle = document.getElementById('theme-toggle');
    const htmlElement = document.documentElement;
    const navLinkElements = document.querySelectorAll('nav a[href^="#"]');
    const sections = document.querySelectorAll('.section');

    const applyTheme = (theme) => {
        if (theme === 'dark') {
            htmlElement.classList.add('dark');
        } else {
            htmlElement.classList.remove('dark');
        }
        localStorage.setItem('theme', theme);
    };

    function runWithViewTransition(fn) {
        if (!document.startViewTransition) {
            fn();
            return;
        }
        document.startViewTransition(fn);
    }

    const toggleTheme = () => {
        const nextTheme = htmlElement.classList.contains('dark') ? 'light' : 'dark';
        runWithViewTransition(() => applyTheme(nextTheme));
    };

    const storedTheme = localStorage.getItem('theme');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (storedTheme) {
        applyTheme(storedTheme);
    } else {
        applyTheme(systemPrefersDark ? 'dark' : 'light');
    }

    themeToggle.addEventListener('click', toggleTheme);

    hamburger.addEventListener('click', function () {
        navLinks.classList.toggle('active');
        document.body.classList.toggle('nav-open');
    });

    function activateSection(id) {
        sections.forEach(sec => sec.classList.remove('active'));

        const targetSection = document.getElementById(id);
        if (targetSection) {
            targetSection.classList.add('active');
            window.scrollTo({ top: 0, behavior: 'auto' });
        }

        navLinkElements.forEach(link => {
            link.classList.toggle('active', link.getAttribute('href') === '#' + id);
        });
    }

    const initialHash = location.hash && location.hash.startsWith('#')
        ? location.hash.slice(1)
        : sections[0]?.id;
    if (initialHash) {
        activateSection(initialHash);
    }

    navLinks.querySelectorAll('a[href^="#"]').forEach(link => {
        const href = link.getAttribute('href');
        const id = href.slice(1);

        link.addEventListener('click', (event) => {
            event.preventDefault();

            if (navLinks.classList.contains('active')) {
                navLinks.classList.remove('active');
                document.body.classList.remove('nav-open');
            }

            const fn = () => {
                activateSection(id);
                history.pushState(null, '', '#' + id);
            };

            if (document.startViewTransition) {
                document.startViewTransition(fn);
            } else {
                fn();
            }
        });
    });

    window.addEventListener('popstate', () => {
        const hash = location.hash && location.hash.startsWith('#')
            ? location.hash.slice(1)
            : sections[0]?.id;
        if (hash) activateSection(hash);
    });

    const handleScroll = () => {
        if (window.pageYOffset > 300) {
            backToTopButton.classList.add('visible');
        } else {
            backToTopButton.classList.remove('visible');
        }
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
});

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
