
document.addEventListener('DOMContentLoaded', function () {
    const backToTopButton = document.querySelector('.back-to-top');
    const hamburger = document.getElementById('hamburger-menu');
    const navLinks = document.getElementById('nav-links');
    const themeToggle = document.getElementById('theme-toggle');
    const htmlElement = document.documentElement;

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

    themeToggle.addEventListener('click', toggleTheme);

    hamburger.addEventListener('click', function () {
        navLinks.classList.toggle('active');
        document.body.classList.toggle('nav-open');
    });

    navLinks.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (navLinks.classList.contains('active')) {
                navLinks.classList.remove('active');
                document.body.classList.remove('nav-open');
            }
        });
    });

    const sections = document.querySelectorAll('section[id]');
    const navLinkElements = document.querySelectorAll('nav a[href^="#"]');

    const handleScroll = () => {
        if (window.pageYOffset > 300) {
            backToTopButton.classList.add('visible');
        } else {
            backToTopButton.classList.remove('visible');
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
            if (link.getAttribute('href') === '#' + current) {
                link.classList.add('active');
            }
        });
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
});

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
