/**
 * Универсальный скрипт для скрытия/показа фильтров
 * Сохраняет состояние в localStorage
 */

(function() {
    'use strict';

    /**
     * Инициализация toggle фильтров
     * @param {string} wrapperSelector - селектор обертки фильтров
     * @param {string} storageKey - ключ для localStorage
     */
    function initFiltersToggle(wrapperSelector, storageKey) {
        const wrapper = document.querySelector(wrapperSelector);
        if (!wrapper) {
            return;
        }

        let toggleHeader = wrapper.querySelector('.filters-toggle-header');
        let filtersCard = wrapper.querySelector('.search-filters-card, .filters, .telegram-filters, .search-filters-card-compact, .user-search-filters');
        
        // Если нет toggle header или filtersCard, не можем инициализировать
        if (!filtersCard || !toggleHeader) {
            return;
        }
        
        // Проверяем, не инициализирован ли уже обработчик
        if (toggleHeader.hasAttribute('data-filter-toggle-initialized')) {
            return;
        }
        toggleHeader.setAttribute('data-filter-toggle-initialized', 'true');
        
        // Если нет toggle header, создаем его (эта проверка не нужна, но оставляем для совместимости)
        if (!toggleHeader) {
            toggleHeader = document.createElement('div');
            toggleHeader.className = 'filters-toggle-header';
            toggleHeader.innerHTML = `
                <div class="filters-toggle-title">
                    <i class="fas fa-filter filters-toggle-icon"></i>
                    <span>Фильтры</span>
                </div>
            `;
            wrapper.insertBefore(toggleHeader, filtersCard);
        }

        // Получаем сохраненное состояние
        const savedState = localStorage.getItem(storageKey);
        // Для чатов фильтры скрыты по умолчанию, если нет активных фильтров
        const hasActiveFilters = wrapper.getAttribute('data-has-filters') === 'true';
        const defaultCollapsed = storageKey === 'filters_toggle_chats' && !hasActiveFilters;
        
        // Если есть активные фильтры, всегда показываем их (если состояние не сохранено)
        let isCollapsed;
        if (hasActiveFilters && savedState === null) {
            isCollapsed = false;
            localStorage.setItem(storageKey, 'expanded');
        } else {
            isCollapsed = savedState === null ? defaultCollapsed : savedState === 'collapsed';
        }

        // Проверяем, установлены ли классы из PHP (начальное состояние)
        const hasInitialCollapsed = toggleHeader.classList.contains('collapsed') || filtersCard.classList.contains('collapsed');
        const hasInitialExpanded = filtersCard.classList.contains('expanded');
        
        // Если классы уже установлены из PHP, используем их, иначе применяем логику из localStorage
        if (hasInitialCollapsed || hasInitialExpanded) {
            // Классы уже установлены из PHP, просто синхронизируем состояние
            if (hasInitialCollapsed && !hasInitialExpanded) {
                isCollapsed = true;
            } else {
                isCollapsed = false;
            }
        }

        // Устанавливаем начальное состояние
        if (isCollapsed) {
            toggleHeader.classList.add('collapsed');
            filtersCard.classList.add('collapsed');
            filtersCard.classList.remove('expanded');
            // Убеждаемся, что фильтры скрыты (если еще не скрыты через inline стили)
            if (!filtersCard.style.maxHeight || filtersCard.style.maxHeight !== '0px') {
                // Сохраняем inline стили, если они есть, иначе устанавливаем новые
                if (!filtersCard.hasAttribute('data-original-style')) {
                    filtersCard.setAttribute('data-original-style', filtersCard.getAttribute('style') || '');
                }
                filtersCard.style.maxHeight = '0';
                filtersCard.style.opacity = '0';
                filtersCard.style.paddingTop = '0';
                filtersCard.style.paddingBottom = '0';
                filtersCard.style.marginTop = '0';
                filtersCard.style.marginBottom = '0';
                filtersCard.style.overflow = 'hidden';
            }
        } else {
            toggleHeader.classList.remove('collapsed');
            filtersCard.classList.remove('collapsed');
            filtersCard.classList.add('expanded');
            // Убеждаемся, что фильтры видны - очищаем inline стили, которые могли скрывать
            if (filtersCard.style.maxHeight === '0' || filtersCard.style.maxHeight === '0px') {
                filtersCard.style.maxHeight = '';
                filtersCard.style.opacity = '';
                filtersCard.style.overflow = '';
                filtersCard.style.padding = '';
                filtersCard.style.margin = '';
            }
        }
        
        // Отмечаем, что фильтры инициализированы, включаем transition
        setTimeout(() => {
            filtersCard.classList.add('initialized');
        }, 100);

        // Обработчик клика на заголовок
        toggleHeader.addEventListener('click', function(e) {
            // Предотвращаем стандартное поведение
            e.preventDefault();
            e.stopPropagation();
            
            // Переключаем состояние - проверяем класс expanded
            const isCurrentlyCollapsed = !filtersCard.classList.contains('expanded');
            
            if (isCurrentlyCollapsed) {
                // Показываем фильтры
                toggleHeader.classList.remove('collapsed');
                filtersCard.classList.remove('collapsed');
                filtersCard.classList.add('expanded');
                filtersCard.classList.add('initialized');
                
                // Очищаем все inline стили, которые могут мешать
                filtersCard.style.removeProperty('max-height');
                filtersCard.style.removeProperty('padding-top');
                filtersCard.style.removeProperty('padding-bottom');
                filtersCard.style.removeProperty('margin-top');
                filtersCard.style.removeProperty('margin-bottom');
                filtersCard.style.removeProperty('display');
                filtersCard.style.removeProperty('visibility');
                
                // Убеждаемся, что элемент видим для получения высоты
                filtersCard.style.overflow = 'hidden';
                filtersCard.style.opacity = '0';
                filtersCard.style.display = 'block';
                
                // Получаем целевую высоту
                const targetHeight = filtersCard.scrollHeight;
                
                // Устанавливаем начальные значения для анимации
                filtersCard.style.maxHeight = '0';
                
                // Запускаем анимацию
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        filtersCard.style.maxHeight = targetHeight + 'px';
                        filtersCard.style.opacity = '1';
                    });
                });
                
                // После анимации убираем ограничения, но сохраняем класс expanded
                setTimeout(() => {
                    // Оставляем max-height пустым для естественной высоты
                    if (filtersCard.classList.contains('expanded')) {
                        filtersCard.style.removeProperty('max-height');
                        filtersCard.style.removeProperty('overflow');
                        filtersCard.style.removeProperty('opacity');
                    }
                }, 300);
                
                localStorage.setItem(storageKey, 'expanded');
            } else {
                // Скрываем фильтры
                // Сначала получаем текущую высоту ДО изменения классов
                const currentHeight = filtersCard.scrollHeight;
                
                // Устанавливаем overflow и фиксируем текущую высоту перед изменением классов
                filtersCard.style.overflow = 'hidden';
                filtersCard.style.maxHeight = currentHeight + 'px';
                
                // Принудительно запускаем reflow для применения стилей
                void filtersCard.offsetHeight;
                
                // Теперь меняем классы
                toggleHeader.classList.add('collapsed');
                filtersCard.classList.add('collapsed');
                filtersCard.classList.remove('expanded');
                
                // Затем анимируем закрытие
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        filtersCard.style.maxHeight = '0';
                        filtersCard.style.opacity = '0';
                        filtersCard.style.paddingTop = '0';
                        filtersCard.style.paddingBottom = '0';
                        filtersCard.style.marginTop = '0';
                        filtersCard.style.marginBottom = '0';
                    });
                });
                
                localStorage.setItem(storageKey, 'collapsed');
            }
        });
    }

    /**
     * Инициализация для всех страниц при загрузке DOM
     */
    document.addEventListener('DOMContentLoaded', function() {
        // Определяем текущую страницу и инициализируем соответствующий toggle
        const path = window.location.pathname;
        
        // Маппинг страниц на селекторы и ключи storage
        const pageConfigs = [
            {
                selector: '.filters-wrapper',
                key: 'filters_toggle_general'
            },
            {
                selector: '.community-filters-wrapper',
                key: 'filters_toggle_community'
            },
            {
                selector: '.users-search-wrapper',
                key: 'filters_toggle_users'
            },
            {
                selector: '.depository-filters-wrapper',
                key: 'filters_toggle_depository'
            },
            {
                selector: '.chats-filters-wrapper',
                key: 'filters_toggle_chats'
            },
            {
                selector: '.telegram-filters',
                key: 'filters_toggle_telegram'
            }
        ];

        // Инициализируем все найденные обертки
        pageConfigs.forEach(config => {
            const wrappers = document.querySelectorAll(config.selector);
            wrappers.forEach((wrapper, index) => {
                // Пропускаем скрытые элементы (например, в неактивных табах)
                // но все равно инициализируем их, так как они могут стать видимыми
                const key = wrappers.length > 1 
                    ? `${config.key}_${index}` 
                    : config.key;
                
                // Для каждого wrapper создаем уникальный селектор
                let specificSelector;
                if (wrappers.length > 1) {
                    // Используем data-атрибут для более надежной идентификации
                    if (!wrapper.hasAttribute('data-filter-index')) {
                        wrapper.setAttribute('data-filter-index', index);
                    }
                    specificSelector = `${config.selector}[data-filter-index="${index}"]`;
                    if (!document.querySelector(specificSelector)) {
                        // Fallback на nth-of-type
                        specificSelector = `${config.selector}:nth-of-type(${index + 1})`;
                    }
                } else {
                    specificSelector = config.selector;
                }
                
                // Инициализируем с небольшой задержкой для элементов в скрытых табах
                setTimeout(() => {
                initFiltersToggle(specificSelector, key);
                }, index * 50);
            });
        });

        // Для страниц без обертки ищем search-filters-card напрямую
        const standaloneFilters = document.querySelectorAll('.search-filters-card:not(.filters-wrapper .search-filters-card)');
        standaloneFilters.forEach((card, index) => {
            const pageKey = `filters_toggle_${path.replace(/[^a-z0-9]/gi, '_')}_${index}`;
            
            // Создаем обертку и toggle header если их нет
            if (!card.parentElement.classList.contains('filters-wrapper')) {
                const wrapper = document.createElement('div');
                wrapper.className = 'filters-wrapper';
                
                const toggleHeader = document.createElement('div');
                toggleHeader.className = 'filters-toggle-header';
                toggleHeader.innerHTML = `
                    <div class="filters-toggle-title">
                        <i class="fas fa-filter filters-toggle-icon"></i>
                        <span>Фильтры</span>
                    </div>
                `;
                
                card.parentNode.insertBefore(wrapper, card);
                wrapper.appendChild(toggleHeader);
                wrapper.appendChild(card);
                
                // Инициализируем после создания обертки
                setTimeout(() => {
                    initFiltersToggle(`.filters-wrapper:has(.search-filters-card)`, pageKey);
                }, 0);
            }
        });

        // Для старых .filters без обертки
        const oldFilters = document.querySelectorAll('.filters:not(.filters-wrapper .filters)');
        oldFilters.forEach((filters, index) => {
            const pageKey = `filters_toggle_${path.replace(/[^a-z0-9]/gi, '_')}_old_${index}`;
            
            if (!filters.parentElement.classList.contains('filters-wrapper')) {
                const wrapper = document.createElement('div');
                wrapper.className = 'filters-wrapper';
                
                const toggleHeader = document.createElement('div');
                toggleHeader.className = 'filters-toggle-header';
                toggleHeader.innerHTML = `
                    <div class="filters-toggle-title">
                        <i class="fas fa-filter filters-toggle-icon"></i>
                        <span>Фильтры</span>
                    </div>
                `;
                
                filters.parentNode.insertBefore(wrapper, filters);
                wrapper.appendChild(toggleHeader);
                wrapper.appendChild(filters);
                
                // Инициализируем после создания обертки
                setTimeout(() => {
                    initFiltersToggle(`.filters-wrapper:has(.filters)`, pageKey);
                }, 0);
            }
        });
    });
})();
