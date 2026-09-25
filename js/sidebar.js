/**
 * JavaScript для управления боковым меню
 */

// Закрытие мобильного меню (используется крестиком)
function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    if (!sidebar) {
        console.error('Sidebar element not found');
        return;
    }
    
    // Убираем все состояния, которые могут мешать закрытию
    sidebar.classList.remove('active');
    sidebar.classList.remove('collapsed');
    sidebar.classList.remove('hidden');
    
    // Для мобильных устройств убеждаемся, что sidebar скрыт
    if (window.innerWidth <= 768) {
        // Принудительно устанавливаем transform для закрытия
        sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
    } else {
        // Для десктопа сбрасываем стили
        sidebar.style.removeProperty('transform');
        sidebar.style.removeProperty('left');
    }
    
    if (overlay) {
        overlay.classList.remove('active');
    }
    
    document.body.classList.remove('sidebar-open');
}

// Переключение мобильного меню
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    if (!sidebar) {
        console.error('Sidebar element not found');
        return;
    }
    
    const isActive = sidebar.classList.contains('active');
    
    if (isActive) {
        // Закрываем меню
        closeSidebar();
    } else {
        // Открываем меню
        sidebar.classList.add('active');
        if (window.innerWidth <= 768) {
            sidebar.style.setProperty('transform', 'translateX(0)', 'important');
        }
        
        if (overlay) {
            overlay.classList.add('active');
        }
        document.body.classList.add('sidebar-open');
    }
}

// Делаем функции глобальными для доступа из onclick
window.closeSidebar = closeSidebar;
window.toggleSidebar = toggleSidebar;

// Переключение состояния меню (развернуто -> свернуто -> скрыто -> развернуто)
function toggleSidebarCollapse() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    
    if (sidebar && window.innerWidth > 768) {
        // Три состояния: развернуто, свернуто, скрыто
        if (sidebar.classList.contains('hidden')) {
            // Скрыто -> развернуто
            sidebar.classList.remove('hidden');
            sidebar.classList.remove('collapsed');
            sidebar.style.left = '0'; // Восстанавливаем позицию
            localStorage.setItem('sidebarState', 'expanded');
            if (toggleBtn) {
                toggleBtn.style.display = 'none';
            }
        } else if (sidebar.classList.contains('collapsed')) {
            // Свернуто -> скрыто
            sidebar.classList.add('hidden');
            sidebar.classList.remove('collapsed');
            sidebar.style.left = '-260px'; // Сдвигаем влево
            localStorage.setItem('sidebarState', 'hidden');
            if (toggleBtn) {
                toggleBtn.style.display = 'block';
            }
        } else {
            // Развернуто -> свернуто
            sidebar.classList.add('collapsed');
            sidebar.style.left = '0'; // Убеждаемся, что позиция корректна
            localStorage.setItem('sidebarState', 'collapsed');
            if (toggleBtn) {
                toggleBtn.style.display = 'none';
            }
        }
    }
}

// Показать скрытое меню (из header)
function showSidebar() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    
    if (sidebar && window.innerWidth > 768) {
        // Убираем скрытое состояние и возвращаем позицию
        sidebar.classList.remove('hidden');
        sidebar.style.left = '0'; // Восстанавливаем позицию
        
        // Если было свернуто, восстанавливаем свернутое состояние
        const savedState = localStorage.getItem('sidebarState');
        if (savedState === 'collapsed') {
            sidebar.classList.add('collapsed');
            localStorage.setItem('sidebarState', 'collapsed');
        } else {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarState', 'expanded');
        }
        // Скрываем кнопку показа
        if (toggleBtn) {
            toggleBtn.style.display = 'none';
        }
    } else {
        // На мобильных используем стандартное открытие
        toggleSidebar();
    }
}

// Закрытие меню при клике на overlay
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    // Восстанавливаем состояние меню из localStorage
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (sidebar && window.innerWidth > 768) {
        const savedState = localStorage.getItem('sidebarState');
        
        // Временно отключаем переходы для предотвращения мигания
        const originalTransition = sidebar.style.transition;
        const appContent = document.querySelector('.app-content');
        const originalContentTransition = appContent ? appContent.style.transition : '';
        
        if (sidebar) {
            sidebar.style.transition = 'none';
        }
        if (appContent) {
            appContent.style.transition = 'none';
        }
        
        if (savedState === 'collapsed') {
            sidebar.classList.add('collapsed');
            sidebar.classList.remove('hidden');
            sidebar.style.left = '0'; // Восстанавливаем позицию
            if (toggleBtn) {
                toggleBtn.style.display = 'none';
            }
        } else if (savedState === 'hidden') {
            sidebar.classList.add('hidden');
            sidebar.classList.remove('collapsed');
            sidebar.style.left = '-260px'; // Сдвигаем влево
            if (toggleBtn) {
                toggleBtn.style.display = 'block';
            }
        } else {
            // Развернуто (по умолчанию)
            sidebar.classList.remove('collapsed');
            sidebar.classList.remove('hidden');
            sidebar.style.left = '0'; // Восстанавливаем позицию
            if (toggleBtn) {
                toggleBtn.style.display = 'none';
            }
        }
        
        // Восстанавливаем переходы после небольшой задержки
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                if (sidebar) {
                    sidebar.style.transition = originalTransition || '';
                }
                if (appContent) {
                    appContent.style.transition = originalContentTransition || '';
                }
            });
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            toggleSidebar();
        });
    }
    
    // Закрытие меню при клике на ссылку (на мобильных устройствах)
    const sidebarLinks = document.querySelectorAll('#sidebar .sidebar-nav a');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        });
    });
    
    // Определение активного пункта меню по текущему URL
    const currentPath = window.location.pathname;
    sidebarLinks.forEach(link => {
        if (link.getAttribute('href') === currentPath || currentPath.startsWith(link.getAttribute('href'))) {
            link.classList.add('active');
        }
    });
    
    // Определение активного пункта мобильного меню
    const mobileNavItems = document.querySelectorAll('.mobile-nav-item');
    mobileNavItems.forEach(item => {
        const itemHref = item.getAttribute('href');
        if (currentPath === itemHref || currentPath.startsWith(itemHref)) {
            item.classList.add('active');
        }
    });
});

// Закрытие меню при изменении размера окна (если стало десктопом)
window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    if (window.innerWidth > 768) {
        // На десктопе убираем мобильное меню
        if (sidebar && overlay) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
        }
        
        // Восстанавливаем состояние меню из localStorage
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        if (sidebar) {
            const savedState = localStorage.getItem('sidebarState');
            if (savedState === 'collapsed') {
                sidebar.classList.add('collapsed');
                sidebar.classList.remove('hidden');
                sidebar.style.left = '0'; // Восстанавливаем позицию
                if (toggleBtn) {
                    toggleBtn.style.display = 'none';
                }
            } else if (savedState === 'hidden') {
                sidebar.classList.add('hidden');
                sidebar.classList.remove('collapsed');
                sidebar.style.left = '-260px'; // Сдвигаем влево
                if (toggleBtn) {
                    toggleBtn.style.display = 'block';
                }
            } else {
                sidebar.classList.remove('collapsed');
                sidebar.classList.remove('hidden');
                sidebar.style.left = '0'; // Восстанавливаем позицию
                if (toggleBtn) {
                    toggleBtn.style.display = 'none';
                }
            }
        }
    } else {
        // На мобильных устройствах убираем collapsed и hidden состояние
        if (sidebar) {
            sidebar.classList.remove('collapsed');
            sidebar.classList.remove('hidden');
        }
    }
});


