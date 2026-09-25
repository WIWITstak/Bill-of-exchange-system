/**
 * Скрипт для создания транзакции
 */

let currentStep = 1;
let selectedBuyer = null;
let searchTimeout = null;

function initTransactionCreate() {
    // Инициализация поиска пользователей
    const searchInput = document.getElementById('search_user');
    const resultsDiv = document.getElementById('user-search-results');
    if (searchInput) {
        searchInput.addEventListener('input', handleUserSearch);
        searchInput.addEventListener('focus', function() {
            // Показываем результаты если есть запрос или показываем всех
            if (this.value.length >= 2 || this.value.length === 0) {
                handleUserSearch();
            }
        });
    }
    
    // Кнопка показать всех пользователей
    const btnShowAll = document.getElementById('btn-show-all');
    if (btnShowAll) {
        btnShowAll.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            handleUserSearch();
        });
    }
    
    // Инициализация фильтров
    const filterUserType = document.getElementById('filter_user_type');
    const filterMinRating = document.getElementById('filter_min_rating');
    const filterSort = document.getElementById('filter_sort');
    const filterOnlyActive = document.getElementById('filter_only_active');
    const btnResetFilters = document.getElementById('btn-reset-filters');
    const filtersToggle = document.getElementById('buyer-filters-toggle');
    const filtersContent = document.getElementById('buyer-filters-content');
    const activeFiltersCount = document.getElementById('active-filters-count');
    
    // Инициализация toggle фильтров
    if (filtersToggle && filtersContent) {
        // Проверяем сохраненное состояние
        const isCollapsed = localStorage.getItem('buyer-filters-collapsed') === 'true';
        if (isCollapsed) {
            filtersContent.classList.add('collapsed');
            filtersContent.classList.remove('expanded');
            filtersToggle.classList.add('collapsed');
        } else {
            filtersContent.classList.add('expanded');
            filtersContent.classList.remove('collapsed');
        }
        
        filtersToggle.addEventListener('click', function(e) {
            if (e.target.closest('.btn-filter-reset-small')) return;
            const wasCollapsed = filtersContent.classList.contains('collapsed');
            filtersContent.classList.toggle('collapsed');
            filtersContent.classList.toggle('expanded');
            filtersToggle.classList.toggle('collapsed');
            localStorage.setItem('buyer-filters-collapsed', filtersContent.classList.contains('collapsed'));
        });
    }
    
    // Обновление счетчика активных фильтров
    function updateActiveFiltersCount() {
        if (!activeFiltersCount) return;
        let count = 0;
        if (filterUserType && filterUserType.value) count++;
        if (filterMinRating && filterMinRating.value !== '0') count++;
        if (filterSort && filterSort.value !== 'name') count++;
        if (filterOnlyActive && !filterOnlyActive.checked) count++;
        
        if (count > 0) {
            activeFiltersCount.textContent = `(${count})`;
            activeFiltersCount.style.display = 'inline';
        } else {
            activeFiltersCount.textContent = '';
            activeFiltersCount.style.display = 'none';
        }
    }
    
    if (filterUserType) {
        filterUserType.addEventListener('change', function() {
            updateActiveFiltersCount();
            handleUserSearch();
        });
    }
    if (filterMinRating) {
        filterMinRating.addEventListener('change', function() {
            updateActiveFiltersCount();
            handleUserSearch();
        });
    }
    if (filterSort) {
        filterSort.addEventListener('change', function() {
            updateActiveFiltersCount();
            handleUserSearch();
        });
    }
    if (filterOnlyActive) {
        filterOnlyActive.addEventListener('change', function() {
            updateActiveFiltersCount();
            handleUserSearch();
        });
    }
    if (btnResetFilters) {
        btnResetFilters.addEventListener('click', function(e) {
            e.stopPropagation();
            if (filterUserType) filterUserType.value = '';
            if (filterMinRating) filterMinRating.value = '0';
            if (filterSort) filterSort.value = 'name';
            if (filterOnlyActive) filterOnlyActive.checked = true;
            if (searchInput) searchInput.value = '';
            updateActiveFiltersCount();
            handleUserSearch();
        });
    }
    
    // Инициализация счетчика
    updateActiveFiltersCount();
    
    // Скрываем результаты при клике вне
    document.addEventListener('click', function(e) {
        const searchWrapper = document.querySelector('.user-search-input-wrapper');
        if (resultsDiv && searchWrapper && !searchWrapper.contains(e.target) && !resultsDiv.contains(e.target)) {
            resultsDiv.style.display = 'none';
        }
    });
    
    // Инициализация выбора из контактов
    document.querySelectorAll('.contact-card').forEach(card => {
        const selectBtn = card.querySelector('.btn-select-contact');
        if (selectBtn) {
            selectBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const userId = parseInt(card.dataset.userId);
                selectContactUser(userId);
            });
        }
        // Также можно кликнуть на всю карточку
        card.addEventListener('click', function(e) {
            // Не срабатывает если клик по кнопке или ссылке
            if (!e.target.closest('.btn-select-contact') && !e.target.closest('.btn-view-profile')) {
                const userId = parseInt(this.dataset.userId);
                selectContactUser(userId);
            }
        });
    });
    
    // Инициализация выбора из товаров
    document.querySelectorAll('.product-quick-item').forEach(item => {
        const useBtn = item.querySelector('.btn-use-product');
        if (useBtn) {
            useBtn.addEventListener('click', function() {
                const productId = parseInt(item.dataset.productId);
                useProduct(productId);
            });
        }
    });
    
    // Инициализация выбора категорий из карточек
    // Обработчик уже установлен через onclick в HTML, дополнительный обработчик не нужен
    // чтобы избежать двойного вызова
    
    // Кнопка удаления выбранного покупателя
    const btnRemoveBuyer = document.getElementById('btn-remove-buyer');
    if (btnRemoveBuyer) {
        btnRemoveBuyer.addEventListener('click', function() {
            clearSelectedBuyer();
        });
    }
    
    // Счетчик символов для описания и валидация
    const descriptionInput = document.getElementById('description');
    const descriptionCounter = document.getElementById('description-counter');
    if (descriptionInput && descriptionCounter) {
        // Объединяем обработчики для описания
        descriptionInput.addEventListener('input', function() {
            const length = this.value.length;
            descriptionCounter.textContent = length;
            
            // Валидация с визуальной обратной связью
            validateDescriptionField(this);
            
            // Обновляем предварительный просмотр
            updatePreview();
        });
        descriptionCounter.textContent = descriptionInput.value.length;
        validateDescriptionField(descriptionInput);
    }
    
    // Валидация полей при вводе
    const amountInput = document.getElementById('amount');
    const maturityInput = document.getElementById('maturity_days');
    
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            validateAmountField();
            updatePreview();
        });
        amountInput.addEventListener('blur', validateAmountField);
    }
    
    if (maturityInput) {
        maturityInput.addEventListener('input', function() {
            validateMaturityField();
            updatePreview();
        });
        maturityInput.addEventListener('blur', validateMaturityField);
    }
    
    // Валидация формы
    const form = document.getElementById('transactionForm');
    if (form) {
        form.addEventListener('submit', validateForm);
    }
    
    // Инициализация автокомплита категорий
    initCategoryAutocomplete();
    
    // Отслеживание изменений для обновления превью
    const categoryInput = document.getElementById('category');
    const typeInputs = document.querySelectorAll('input[name="transaction_type"]');
    
    if (categoryInput) {
        categoryInput.addEventListener('input', updatePreview);
    }
    typeInputs.forEach(input => {
        input.addEventListener('change', updatePreview);
    });
    
    // Инициализация первого шага
    goToStep(1);
    
    // Если покупатель уже выбран (preselected), загружаем его данные
    // Это обрабатывается через inline script в PHP, который вызывает selectBuyer()
    const buyerIdInput = document.getElementById('buyer_id');
    if (buyerIdInput && buyerIdInput.value) {
        const buyerId = parseInt(buyerIdInput.value);
        if (buyerId > 0 && !selectedBuyer) {
            // Если selectedBuyer еще не установлен, устанавливаем его
            selectedBuyer = { id: buyerId };
            updateNextButton();
        }
    }
    
    // Проверяем есть ли preselected buyer после загрузки всех скриптов
    // Это обрабатывается через inline script в PHP который вызывает selectBuyer() и goToStep(2)
    // Здесь только проверяем что все инициализировано правильно
}

function handleUserSearch() {
    const searchInput = document.getElementById('search_user');
    const resultsDiv = document.getElementById('user-search-results');
    const query = searchInput ? searchInput.value.trim() : '';
    
    clearTimeout(searchTimeout);
    
    // Получаем значения фильтров
    const filterUserType = document.getElementById('filter_user_type')?.value || '';
    const filterMinRating = document.getElementById('filter_min_rating')?.value || '0';
    const filterSort = document.getElementById('filter_sort')?.value || 'name';
    const filterOnlyActive = document.getElementById('filter_only_active')?.checked !== false;
    
    // Если запрос меньше 2 символов и не пустой, показываем placeholder
    if (query.length > 0 && query.length < 2) {
        if (resultsDiv) {
            resultsDiv.innerHTML = '<div class="search-placeholder"><div class="search-placeholder-icon">⌨️</div><div class="search-placeholder-text">Введите минимум 2 символа для поиска</div></div>';
            resultsDiv.style.display = 'block';
        }
        const resultsInfo = document.getElementById('search-results-info');
        if (resultsInfo) resultsInfo.style.display = 'none';
        return;
    }
    
    // Если запрос пустой, показываем placeholder или всех пользователей
    if (query.length === 0) {
        // Показываем всех пользователей при пустом запросе
    }
    
    // Показываем индикатор загрузки
    const resultsInfo = document.getElementById('search-results-info');
    const resultsCount = document.getElementById('search-results-count');
    
    if (resultsDiv) {
        const loadingText = query.length === 0 ? 'Загрузка пользователей...' : 'Поиск...';
        resultsDiv.innerHTML = `<div class="search-loading"><div class="search-spinner"></div>${loadingText}</div>`;
        resultsDiv.style.display = 'block';
    }
    if (resultsInfo) resultsInfo.style.display = 'none';
    
    searchTimeout = setTimeout(function() {
        // Формируем URL с параметрами
        const params = new URLSearchParams();
        if (query) params.append('q', query);
        if (filterUserType) params.append('user_type', filterUserType);
        if (filterMinRating && filterMinRating !== '0') params.append('min_rating', filterMinRating);
        if (filterSort) params.append('sort_by', filterSort);
        if (filterOnlyActive) params.append('only_active', '1');
        params.append('limit', '30');
        
        fetch('/api/user-search.php?' + params.toString())
            .then(response => {
                if (!response.ok) throw new Error('Ошибка сети');
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    resultsDiv.innerHTML = '<div class="search-no-results"><div class="no-results-icon">⚠️</div><div>' + escapeHtml(data.error) + '</div></div>';
                    resultsDiv.style.display = 'block';
                    if (resultsInfo) resultsInfo.style.display = 'none';
                    return;
                }
                
                if (data.users && data.users.length > 0) {
                    let html = '';
                    data.users.forEach(user => {
                        const avatarHtml = user.avatar_url 
                            ? `<img src="${escapeHtml(user.avatar_url)}" alt="" class="user-avatar-img">`
                            : `<div class="user-avatar-initials">${escapeHtml(user.initials || '?')}</div>`;
                        
                        const userTypeLabel = user.user_type === 'legal' ? 'Юр. лицо' : 'Физ. лицо';
                        const userTypeIcon = user.user_type === 'legal' ? '🏢' : '👤';
                        const ratingHtml = user.rating > 0 
                            ? `<span class="user-rating" title="Рейтинг">⭐ ${user.rating.toFixed(2)}</span>` 
                            : '<span class="user-rating-no" title="Нет рейтинга">—</span>';
                        
                        const transactionCount = user.transaction_count || 0;
                        const transactionHtml = transactionCount > 0 
                            ? `<span class="user-transactions" title="Количество сделок с вами">📊 ${transactionCount}</span>` 
                            : '';
                        
                        const companyHtml = (user.company_name && user.company_name.trim()) 
                            ? `<div class="user-company">${escapeHtml(user.company_name)}</div>` 
                            : '';
                        
                        const statusHtml = user.is_active 
                            ? '<span class="user-status-active" title="Активен">✓</span>' 
                            : '<span class="user-status-inactive" title="Неактивен">○</span>';
                        
                        html += `
                            <div class="user-search-result" data-user-id="${user.id}" data-user-rating="${user.rating || 0}" data-transaction-count="${transactionCount}">
                                <div class="user-result-avatar">${avatarHtml}</div>
                                <div class="user-result-info">
                                    <div class="user-result-header">
                                        <div class="user-result-name">${escapeHtml(user.full_name)}</div>
                                        ${statusHtml}
                                    </div>
                                    ${companyHtml}
                                    <div class="user-result-meta">
                                        <span class="user-result-email" title="Email">${escapeHtml(user.email)}</span>
                                        <span class="user-result-type">${userTypeIcon} ${userTypeLabel}</span>
                                        ${ratingHtml}
                                        ${transactionHtml}
                                    </div>
                                </div>
                                <div class="user-result-actions">
                                    <a href="/user.php?id=${user.id}" class="btn-view-user-profile" target="_blank" title="Открыть профиль" onclick="event.stopPropagation()">👁️</a>
                                    <button type="button" class="btn-select-user" onclick="event.stopPropagation(); selectBuyer(${JSON.stringify(user)})">Выбрать</button>
                                </div>
                            </div>
                        `;
                    });
                    resultsDiv.innerHTML = html;
                    resultsDiv.style.display = 'block';
                    
                    // Обновляем счетчик результатов
                    if (resultsCount) resultsCount.textContent = data.users.length;
                    if (resultsInfo) resultsInfo.style.display = 'block';
                    
                    // Добавляем обработчики для клика по карточке
                    resultsDiv.querySelectorAll('.user-search-result').forEach(result => {
                        // Клик по всей карточке выбирает пользователя
                        result.addEventListener('click', function(e) {
                            // Не срабатывает если клик по кнопке или ссылке
                            if (e.target.closest('.btn-select-user') || e.target.closest('.btn-view-user-profile')) {
                                return;
                            }
                            // Находим кнопку выбора и кликаем по ней
                            const selectBtn = this.querySelector('.btn-select-user');
                            if (selectBtn) {
                                selectBtn.click();
                            }
                        });
                    });
                } else {
                    resultsDiv.innerHTML = '<div class="search-no-results"><div class="no-results-icon">🔍</div><div>Пользователи не найдены</div><div class="no-results-hint">Попробуйте изменить фильтры или поисковый запрос</div></div>';
                    resultsDiv.style.display = 'block';
                    if (resultsInfo) resultsInfo.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Ошибка поиска пользователей:', error);
                resultsDiv.innerHTML = '<div class="search-no-results"><div class="no-results-icon">⚠️</div><div>Ошибка при поиске</div><div class="no-results-hint">Попробуйте обновить страницу</div></div>';
                resultsDiv.style.display = 'block';
                if (resultsInfo) resultsInfo.style.display = 'none';
            });
    }, 300);
}

function selectBuyer(user) {
    if (!user || !user.id) {
        console.error('Invalid user data:', user);
        return;
    }
    
    selectedBuyer = user;
    
    const selectedDiv = document.getElementById('selected-buyer');
    const buyerIdInput = document.getElementById('buyer_id');
    const searchInput = document.getElementById('search_user');
    const resultsDiv = document.getElementById('user-search-results');
    
    if (!selectedDiv || !buyerIdInput) {
        console.error('Required elements not found');
        return;
    }
    
    // Заполняем информацию о выбранном покупателе
    const avatarDiv = document.getElementById('selected-buyer-avatar');
    const nameDiv = document.getElementById('selected-buyer-name');
    const detailsDiv = document.getElementById('selected-buyer-details');
    
    if (avatarDiv) {
        avatarDiv.innerHTML = user.avatar_url 
            ? `<img src="${escapeHtml(user.avatar_url)}" alt="" class="selected-avatar-img">`
            : `<div class="selected-avatar-initials">${escapeHtml(user.initials || '?')}</div>`;
    }
    
    if (nameDiv) {
        nameDiv.textContent = user.full_name || 'Пользователь';
    }
    
    if (detailsDiv) {
        const userTypeLabel = user.user_type === 'legal' ? 'Юридическое лицо' : 'Физическое лицо';
        const ratingHtml = user.rating > 0 ? ` • ⭐ ${user.rating.toFixed(2)}` : '';
        const email = user.email || '';
        detailsDiv.innerHTML = `${escapeHtml(email)} • ${userTypeLabel}${ratingHtml}`;
    }
    
    buyerIdInput.value = user.id;
    selectedDiv.style.display = 'block';
    if (resultsDiv) resultsDiv.style.display = 'none';
    if (searchInput) searchInput.value = '';
    
    // Загружаем историю транзакций с этим покупателем
    loadBuyerTransactionHistory(user.id);
    
    // Обновляем предварительный просмотр
    updatePreview();
    
    // Активируем кнопку "Далее"
    updateNextButton();
}

function selectContactUser(userId) {
    // Получаем данные из карточки контакта через data-атрибуты
    const contactCard = document.querySelector(`.contact-card[data-user-id="${userId}"]`);
    if (!contactCard) {
        console.error('Contact card not found for user:', userId);
        return;
    }
    
    const user = {
        id: parseInt(contactCard.dataset.userId),
        full_name: contactCard.dataset.userName || '',
        email: contactCard.dataset.userEmail || '',
        user_type: contactCard.dataset.userType || 'individual',
        avatar_url: contactCard.dataset.userAvatar || '',
        initials: contactCard.dataset.userInitials || '?',
        rating: parseFloat(contactCard.dataset.userRating || '0'),
        company_name: contactCard.dataset.companyName || null,
        transaction_count: parseInt(contactCard.dataset.transactionCount || '0')
    };
    
    selectBuyer(user);
}

function clearSelectedBuyer() {
    selectedBuyer = null;
    const selectedDiv = document.getElementById('selected-buyer');
    const buyerIdInput = document.getElementById('buyer_id');
    const searchInput = document.getElementById('search_user');
    const historyDiv = document.getElementById('buyer-transaction-history');
    const historyList = document.getElementById('buyer-transaction-list');
    
    if (selectedDiv) selectedDiv.style.display = 'none';
    if (buyerIdInput) buyerIdInput.value = '';
    if (searchInput) searchInput.value = '';
    if (historyDiv) historyDiv.style.display = 'none';
    if (historyList) historyList.innerHTML = '';
    
    updatePreview();
    updateNextButton();
}

function useProduct(productId) {
    // Загружаем информацию о товаре через поиск по каталогу или напрямую из DOM
    const productItem = document.querySelector(`[data-product-id="${productId}"]`);
    if (productItem) {
        const productName = productItem.querySelector('.product-quick-name')?.textContent || '';
        const productDesc = productItem.querySelector('.product-quick-desc')?.textContent || '';
        const productCategory = productItem.querySelector('.product-category')?.textContent || '';
        
        const descriptionInput = document.getElementById('description');
        const categoryInput = document.getElementById('category');
        
        // Формируем описание из названия и описания товара
        let description = productName;
        if (productDesc && productDesc.trim()) {
            description += '\n\n' + productDesc.trim();
        }
        
        if (descriptionInput) {
            descriptionInput.value = description;
            const counter = document.getElementById('description-counter');
            if (counter) counter.textContent = descriptionInput.value.length;
        }
        
        if (categoryInput && productCategory && productCategory !== 'Без категории') {
            categoryInput.value = productCategory.trim();
            // Убираем выделение с других карточек категорий
            document.querySelectorAll('.category-card').forEach(c => c.classList.remove('selected'));
        }
        
        // Обновляем превью
        updatePreview();
        
        // Переходим на следующий шаг
        goToStep(2);
    }
}

function goToStep(step) {
    // Валидация перед переходом (только если переходим вперед)
    if (step > currentStep) {
        // Если переходим на шаг 2 и покупатель уже выбран (preselected), пропускаем валидацию
        if (step === 2 && currentStep === 1) {
            const buyerId = document.getElementById('buyer_id')?.value;
            if (buyerId && selectedBuyer) {
                // Покупатель уже выбран, можно переходить без валидации
            } else if (!validateCurrentStep()) {
                return;
            }
        } else if (!validateCurrentStep()) {
            return;
        }
    }
    
    currentStep = step;
    
    // Обновляем видимость шагов
    const sections = ['step1', 'step2', 'step3'];
    sections.forEach((sectionId, index) => {
        const section = document.getElementById(sectionId);
        if (section) {
            section.style.display = (index + 1 === step) ? 'block' : 'none';
        }
    });
    
    // Обновляем индикаторы шагов
    document.querySelectorAll('.step').forEach((stepEl, index) => {
        const stepNum = index + 1;
        stepEl.classList.toggle('active', stepNum === step);
        stepEl.classList.toggle('completed', stepNum < step);
    });
    
    // Обновляем кнопки
    updateNextButton();
    
    // Обновляем предварительный просмотр на шаге 3
    if (step === 3) {
        updatePreview();
    }
    
    // Прокрутка вверх
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validateCurrentStep() {
    if (currentStep === 1) {
        const buyerId = document.getElementById('buyer_id')?.value;
        if (!buyerId || buyerId === '') {
            alert('Пожалуйста, выберите покупателя');
            return false;
        }
    } else if (currentStep === 2) {
        const description = document.getElementById('description')?.value.trim();
        if (!description || description.length < 10) {
            alert('Пожалуйста, укажите описание товара/услуги (минимум 10 символов)');
            return false;
        }
    } else if (currentStep === 3) {
        const maturityDays = document.getElementById('maturity_days')?.value;
        if (maturityDays && maturityDays.trim() !== '') {
            const days = parseInt(maturityDays);
            if (isNaN(days) || days < 1 || days > 365) {
                alert('Срок погашения должен быть от 1 до 365 дней');
                return false;
            }
        }
        const amount = document.getElementById('amount')?.value;
        if (amount && amount.trim() !== '') {
            const amountValue = parseFloat(amount);
            if (isNaN(amountValue) || amountValue < 0) {
                alert('Сумма должна быть положительным числом');
                return false;
            }
        }
    }
    return true;
}

function updateNextButton() {
    const nextButtons = document.querySelectorAll('.btn-next-step');
    const buyerIdInput = document.getElementById('buyer_id');
    const isBuyerSelected = selectedBuyer !== null || (buyerIdInput && buyerIdInput.value);
    
    nextButtons.forEach(btn => {
        if (currentStep === 1) {
            btn.disabled = !isBuyerSelected;
        } else {
            btn.disabled = false;
        }
    });
}

function validateForm(e) {
    if (!validateCurrentStep()) {
        e.preventDefault();
        goToStep(currentStep);
        return false;
    }
    
    const buyerId = document.getElementById('buyer_id')?.value;
    const description = document.getElementById('description')?.value.trim();
    const maturityDays = document.getElementById('maturity_days')?.value;
    const amount = document.getElementById('amount')?.value;
    
    if (!buyerId) {
        e.preventDefault();
        alert('Пожалуйста, выберите покупателя');
        goToStep(1);
        return false;
    }
    
    if (!description || description.length < 10) {
        e.preventDefault();
        alert('Пожалуйста, укажите описание товара/услуги (минимум 10 символов)');
        goToStep(2);
        return false;
    }
    
    if (maturityDays && maturityDays.trim() !== '') {
        const days = parseInt(maturityDays);
        if (isNaN(days) || days < 1 || days > 365) {
            e.preventDefault();
            alert('Срок погашения должен быть от 1 до 365 дней');
            goToStep(3);
            return false;
        }
    }
    
    if (amount && amount.trim() !== '') {
        const amountValue = parseFloat(amount);
        if (isNaN(amountValue) || amountValue < 0) {
            e.preventDefault();
            alert('Сумма должна быть положительным числом');
            goToStep(3);
            return false;
        }
    }
    
    return true;
}

function initCategoryAutocomplete() {
    const categoryInput = document.getElementById('category');
    const suggestionsDiv = document.getElementById('category-suggestions');
    let searchTimeout = null;
    
    if (!categoryInput || !suggestionsDiv) return;
    
    function searchCategories(query) {
        if (query.length < 1) {
            suggestionsDiv.innerHTML = '';
            suggestionsDiv.style.display = 'none';
            return;
        }
        
        fetch('/api/categories.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    suggestionsDiv.innerHTML = '';
                    suggestionsDiv.style.display = 'none';
                    return;
                }
                
                if (data.categories && data.categories.length > 0) {
                    let html = '';
                    data.categories.forEach(category => {
                        html += `
                            <div class="category-suggestion" onclick="selectCategory('${category.name.replace(/'/g, "\\'")}')">
                                <span class="category-icon">${category.icon || '📦'}</span>
                                <span class="category-name">${category.name}</span>
                                ${category.description ? `<small class="category-desc">${category.description}</small>` : ''}
                            </div>
                        `;
                    });
                    suggestionsDiv.innerHTML = html;
                    suggestionsDiv.style.display = 'block';
                } else {
                    suggestionsDiv.innerHTML = '';
                    suggestionsDiv.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Ошибка поиска категорий:', error);
                suggestionsDiv.innerHTML = '';
                suggestionsDiv.style.display = 'none';
            });
    }
    
    window.selectCategory = function(name) {
        if (categoryInput) categoryInput.value = name;
        if (suggestionsDiv) {
            suggestionsDiv.innerHTML = '';
            suggestionsDiv.style.display = 'none';
        }
        // Убираем выделение с карточек категорий
        document.querySelectorAll('.category-card').forEach(c => c.classList.remove('selected'));
        updatePreview();
    };
    
    categoryInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            searchCategories(query);
        }, 300);
    });
    
    // Скрываем подсказки при клике вне поля
    document.addEventListener('click', function(e) {
        if (!categoryInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
            suggestionsDiv.style.display = 'none';
        }
    });
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

// Inline валидация полей
function validateDescriptionField(input) {
    if (!input || !input.parentElement) return;
    
    const value = input.value.trim();
    const minLength = 10;
    const isValid = value.length >= minLength;
    
    // Убираем предыдущие сообщения об ошибках
    const existingError = input.parentElement.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
    
    // Убираем классы валидации
    input.classList.remove('field-invalid', 'field-valid');
    
    if (value.length > 0) {
        if (isValid) {
            input.classList.add('field-valid');
        } else {
            input.classList.add('field-invalid');
            const errorMsg = document.createElement('div');
            errorMsg.className = 'field-error';
            errorMsg.textContent = `Минимум ${minLength} символов (сейчас: ${value.length})`;
            input.parentElement.appendChild(errorMsg);
        }
    }
}

function validateAmountField() {
    const input = document.getElementById('amount');
    if (!input || !input.parentElement) return;
    
    const value = parseFloat(input.value);
    const existingError = input.parentElement.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
    
    input.classList.remove('field-invalid', 'field-valid');
    
    const inputValue = input.value.trim();
    if (inputValue !== '') {
        if (isNaN(value) || value < 0) {
            input.classList.add('field-invalid');
            const errorMsg = document.createElement('div');
            errorMsg.className = 'field-error';
            errorMsg.textContent = 'Сумма должна быть положительным числом';
            input.parentElement.appendChild(errorMsg);
        } else if (value > 0) {
            input.classList.add('field-valid');
        }
    }
}

function validateMaturityField() {
    const input = document.getElementById('maturity_days');
    if (!input || !input.parentElement) return;
    
    const value = parseInt(input.value);
    const existingError = input.parentElement.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
    
    input.classList.remove('field-invalid', 'field-valid');
    
    const inputValue = input.value.trim();
    if (inputValue !== '') {
        if (isNaN(value) || value < 1 || value > 365 || value < 0) {
            input.classList.add('field-invalid');
            const errorMsg = document.createElement('div');
            errorMsg.className = 'field-error';
            if (isNaN(value) || value < 0) {
                errorMsg.textContent = 'Срок должен быть положительным числом';
            } else if (value < 1) {
                errorMsg.textContent = 'Срок должен быть не менее 1 дня';
            } else if (value > 365) {
                errorMsg.textContent = 'Срок не может превышать 365 дней';
            } else {
                errorMsg.textContent = 'Срок должен быть от 1 до 365 дней';
            }
            input.parentElement.appendChild(errorMsg);
        } else {
            input.classList.add('field-valid');
        }
    }
}

// Загрузка истории транзакций с покупателем
function loadBuyerTransactionHistory(buyerId) {
    const historyDiv = document.getElementById('buyer-transaction-history');
    const historyList = document.getElementById('buyer-transaction-list');
    
    if (!historyDiv || !historyList || !buyerId || buyerId <= 0) {
        if (historyDiv) historyDiv.style.display = 'none';
        return;
    }
    
    // Показываем индикатор загрузки
    historyList.innerHTML = '<div style="padding: 10px; text-align: center; color: var(--text-secondary);">Загрузка...</div>';
    historyDiv.style.display = 'block';
    
    fetch(`/api/transaction_history.php?buyer_id=${buyerId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Ошибка сети');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                historyDiv.style.display = 'none';
                return;
            }
            
            if (data.transactions && data.transactions.length > 0) {
                let html = '';
                data.transactions.forEach(trans => {
                    const statusLabels = {
                        'pending': 'На рассмотрении',
                        'active': 'Активная',
                        'completed': 'Завершена',
                        'cancelled': 'Отменена'
                    };
                    const statusLabel = statusLabels[trans.status] || trans.status;
                    const statusClass = `status-${trans.status}`;
                    const roleLabel = trans.role === 'seller' ? 'Вы продавец' : 'Вы покупатель';
                    
                    // Форматируем дату
                    let date = '-';
                    if (trans.created_at) {
                        try {
                            date = new Date(trans.created_at).toLocaleDateString('ru-RU', {
                                year: 'numeric',
                                month: '2-digit',
                                day: '2-digit'
                            });
                        } catch (e) {
                            date = trans.created_at;
                        }
                    }
                    
                    html += `
                        <div class="history-transaction-item">
                            <div class="history-transaction-header">
                                <span class="history-transaction-date">${date}</span>
                                <span class="history-transaction-status ${statusClass}">${escapeHtml(statusLabel)}</span>
                            </div>
                            <div class="history-transaction-desc">${escapeHtml(trans.description || '')}</div>
                            <div class="history-transaction-meta">
                                ${trans.category ? `<span class="history-category">${escapeHtml(trans.category)}</span>` : ''}
                                <span class="history-role">${escapeHtml(roleLabel)}</span>
                            </div>
                        </div>
                    `;
                });
                historyList.innerHTML = html;
                historyDiv.style.display = 'block';
            } else {
                historyDiv.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Ошибка загрузки истории:', error);
            historyDiv.style.display = 'none';
        });
}

// Выбор категории из карточки
function selectCategoryCard(card) {
    if (!card) return;
    
    const categoryName = card.dataset.categoryName;
    if (!categoryName) return;
    
    const categoryInput = document.getElementById('category');
    const suggestionsDiv = document.getElementById('category-suggestions');
    
    if (categoryInput) {
        categoryInput.value = categoryName;
    }
    if (suggestionsDiv) {
        suggestionsDiv.innerHTML = '';
        suggestionsDiv.style.display = 'none';
    }
    
    // Визуальная обратная связь
    document.querySelectorAll('.category-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    
    // Обновляем предварительный просмотр
    updatePreview();
    
    // Валидация категории (если нужно)
    if (categoryInput) {
        categoryInput.dispatchEvent(new Event('input'));
    }
}

// Обновление предварительного просмотра
function updatePreview() {
    const previewDiv = document.getElementById('transaction-preview');
    if (!previewDiv) return;
    
    // Показываем превью только на шаге 3
    if (currentStep !== 3) {
        previewDiv.style.display = 'none';
        return;
    }
    
    const buyerNameEl = document.getElementById('selected-buyer-name');
    const descriptionEl = document.getElementById('description');
    const categoryEl = document.getElementById('category');
    const typeInput = document.querySelector('input[name="transaction_type"]:checked');
    
    const buyerName = buyerNameEl ? buyerNameEl.textContent.trim() : '';
    const description = descriptionEl ? descriptionEl.value.trim() : '';
    const category = categoryEl ? categoryEl.value.trim() : '';
    const transactionType = typeInput ? typeInput.value : 'barter';
    
    const typeLabels = {
        'barter': '🔄 Бартер',
        'guarantee': '🛡️ Гарантия',
        'community': '🏘️ Община',
        'mixed': '🔀 Смешанная'
    };
    
    const previewBuyerEl = document.getElementById('preview-buyer');
    const previewTypeEl = document.getElementById('preview-type');
    const previewCategoryEl = document.getElementById('preview-category');
    const previewDescriptionEl = document.getElementById('preview-description');
    
    if (previewBuyerEl) previewBuyerEl.textContent = buyerName || '-';
    if (previewTypeEl) previewTypeEl.textContent = typeLabels[transactionType] || 'Бартер';
    if (previewCategoryEl) previewCategoryEl.textContent = category || '-';
    if (previewDescriptionEl) previewDescriptionEl.textContent = description || '-';
    
    // Показываем превью только если есть данные
    if (selectedBuyer && buyerName && description.length >= 10) {
        previewDiv.style.display = 'block';
    } else {
        previewDiv.style.display = 'none';
    }
}

// Экспортируем функции для глобального доступа
window.selectBuyer = selectBuyer;
window.goToStep = goToStep;
window.selectCategory = function(name) {
    const categoryInput = document.getElementById('category');
    const suggestionsDiv = document.getElementById('category-suggestions');
    if (categoryInput) categoryInput.value = name;
    if (suggestionsDiv) {
        suggestionsDiv.innerHTML = '';
        suggestionsDiv.style.display = 'none';
    }
    // Убираем выделение с карточек категорий
    document.querySelectorAll('.category-card').forEach(c => c.classList.remove('selected'));
    updatePreview();
};
window.selectCategoryCard = selectCategoryCard;
window.updatePreview = updatePreview;
window.loadBuyerTransactionHistory = loadBuyerTransactionHistory;

