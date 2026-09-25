/**
 * Подсветка активной навигационной ссылки
 */
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname;
        const navLinks = document.querySelectorAll('.main-nav .nav-link');
        
        navLinks.forEach(function(link) {
            const linkPath = new URL(link.href).pathname;
            if (currentPath === linkPath || currentPath.startsWith(linkPath + '/')) {
                link.classList.add('active');
            }
        });
    });
})();








